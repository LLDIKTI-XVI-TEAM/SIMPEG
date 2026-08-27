<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\TestCase;

/** Memverifikasi gate migration reservasi terhadap writer audit-first pada PostgreSQL nyata. */
#[Group('guarded-destructive')]
class NonAnnualReservationReconciliationConcurrencyTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;

    private const DESTRUCTIVE_MIGRATION_OPT_IN = 'SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS';

    private const STANDARD_TEST_DATABASE = 'simpeg_test';

    /** Memberi ruang bootstrap worker pada bind mount Podman tanpa melonggarkan timeout proses. */
    private const WORKER_READY_TIMEOUT_MILLISECONDS = 30_000;

    private const RECONCILIATION_MIGRATION = '2026_08_23_000004_reconcile_nonannual_active_reservations.php';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_gate_nowait_menghindari_deadlock_submit_audit_first_dan_retry_bersih(): void
    {
        $fixture = $this->dirtyReservationFixture();
        $directory = storage_path('framework/testing/nonannual-reservation-deadlock-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];

        try {
            $auditId = (string) Str::uuid();
            $submittedRequestId = (string) Str::uuid();
            $auditFirst = $this->startWorker('NonAnnualReservationAuditFirstWorker.php', [
                'mode' => 'interleave',
                'audit_id' => $auditId,
                'audit_entity_id' => (string) Str::uuid(),
                'employee_id' => $fixture['submit_employee']->id,
                'leave_type_id' => $fixture['leave_type']->id,
                'leave_request_id' => $submittedRequestId,
                'attempt' => $directory.'/migration-attempt.json',
                'ready' => $directory.'/audit-ready.json',
                'result' => $directory.'/audit-result.json',
            ]);
            $processes[] = $auditFirst;
            $this->assertTrue($this->waitForFile($directory.'/audit-ready.json'));

            $migration = $this->startWorker('NonAnnualReservationMigrationWorker.php', [
                'attempt' => $directory.'/migration-attempt.json',
                'ready' => $directory.'/migration-ready.json',
                'result' => $directory.'/migration-result.json',
            ]);
            $processes[] = $migration;
            $this->assertTrue($this->waitForFile($directory.'/migration-ready.json'));

            $auditFirst->wait();
            $migration->wait();
            $this->assertTrue($auditFirst->isSuccessful(), $auditFirst->getErrorOutput());
            $this->assertTrue($migration->isSuccessful(), $migration->getErrorOutput());
            $auditResult = $this->readJson($directory.'/audit-result.json');
            $migrationResult = $this->readJson($directory.'/migration-result.json');
            $this->assertTrue((bool) ($auditResult['ok'] ?? false), json_encode($auditResult));
            $this->assertTrue((bool) ($migrationResult['ok'] ?? false), json_encode($migrationResult));
            $this->assertGreaterThanOrEqual(2, (int) ($migrationResult['attempts'] ?? 0));
            $this->assertDatabaseHas('audit_logs', ['id' => $auditId]);
            $this->assertDatabaseHas('leave_requests', ['id' => $submittedRequestId]);
            $this->assertSame(0, $this->reservationNet($fixture['dirty_request']));
            $this->assertDatabaseHas('leave_balance_reservation_events', [
                'dedup_key' => "leave_reservation:{$fixture['dirty_request']->id}:released:nonannual_upgrade:2026",
                'amount' => -20,
            ]);
        } finally {
            $this->stopWorkers($processes);
            File::deleteDirectory($directory);
        }
    }

    public function test_retry_habis_di_savepoint_tidak_meninggalkan_bukti_atau_lock_lalu_retry_bersih_sukses(): void
    {
        $fixture = $this->dirtyReservationFixture();
        $directory = storage_path('framework/testing/nonannual-reservation-exhaustion-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];

        try {
            $blocker = $this->startWorker('NonAnnualReservationAuditFirstWorker.php', [
                'mode' => 'hold',
                'audit_id' => (string) Str::uuid(),
                'audit_entity_id' => (string) Str::uuid(),
                'release' => $directory.'/blocker-release',
                'ready' => $directory.'/blocker-ready.json',
                'result' => $directory.'/blocker-result.json',
            ]);
            $processes[] = $blocker;
            $this->assertTrue($this->waitForFile($directory.'/blocker-ready.json'));

            DB::beginTransaction();

            try {
                DB::statement("SET LOCAL lock_timeout TO '100ms'");
                $startedAt = microtime(true);
                $exception = null;

                try {
                    $this->invokeMigration($fixture['migration'], 'up');
                } catch (RuntimeException $runtimeException) {
                    $exception = $runtimeException;
                }

                if ($exception === null) {
                    $this->fail('Migration wajib berhenti setelah retry gate habis.');
                }

                $this->assertStringContainsString('setelah 80 percobaan', $exception->getMessage());
                $this->assertLessThan(10.0, microtime(true) - $startedAt);
                $this->assertSame(1, DB::transactionLevel());
                $this->assertSame(1, DB::table('leave_balance_reservation_events')->count());
                $this->assertSame(0, DB::table('audit_logs')->count());
                $this->assertSame(20, $this->reservationNet($fixture['dirty_request']));
                $this->assertSame(0, $this->maintenanceLocksHeldByCurrentConnection());

                File::put($directory.'/blocker-release', 'commit');
                $blocker->wait();
                $this->assertTrue($blocker->isSuccessful(), $blocker->getErrorOutput());
                $this->assertTrue((bool) ($this->readJson($directory.'/blocker-result.json')['ok'] ?? false));

                $this->invokeMigration($fixture['migration'], 'up');
                DB::commit();
            } catch (\Throwable $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                throw $exception;
            }

            $this->assertSame(0, $this->reservationNet($fixture['dirty_request']));
            $this->assertSame(1, DB::table('leave_balance_reservation_events')
                ->whereRaw("metadata->>'release_context' = ?", ['nonannual_active_reservation_upgrade'])
                ->count());
            $this->assertSame(1, DB::table('audit_logs')
                ->whereRaw("new_values->>'release_context' = ?", ['nonannual_active_reservation_upgrade'])
                ->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            File::put($directory.'/blocker-release', 'cleanup');
            $this->stopWorkers($processes);
            File::deleteDirectory($directory);
        }
    }

    /** @return array{migration:Migration,dirty_request:LeaveRequest,leave_type:RefJenisCuti,submit_employee:Employee} */
    private function dirtyReservationFixture(): array
    {
        $this->requireStandardDestructiveTestDatabase();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Carbon::setTestNow('2026-08-24 09:00:00');
        $migration = $this->migration();
        $this->invokeMigration($migration, 'down');
        $employee = Employee::factory()->create();
        $submitEmployee = Employee::factory()->create();
        $leaveType = RefJenisCuti::query()->create([
            'nama' => 'Cuti Legacy Audit First',
            'code' => 'sakit_audit_first',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $balance = LeaveBalance::query()->create([
            'employee_id' => $employee->id,
            'tahun' => 2026,
            'jatah_awal' => 12,
            'carry_over' => 0,
            'terpakai' => 0,
            'sisa' => 12,
            'sisa_n2' => 0,
            'sisa_n1' => 0,
            'sisa_tahun_berjalan' => 12,
            'terpakai_tahun_berjalan' => 0,
            'hangus' => 0,
        ]);
        $dirtyRequest = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-01',
            'jumlah_hari_kerja' => 20,
            'alasan' => 'Fixture dirty reservation audit-first.',
            'status' => 'menunggu_approval',
        ]);
        LeaveBalanceReservationEvent::query()->create([
            'employee_id' => $employee->id,
            'leave_request_id' => $dirtyRequest->id,
            'leave_balance_id' => $balance->id,
            'tahun' => 2026,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RESERVED,
            'amount' => 20,
            'reason' => 'Fixture dirty reservation audit-first.',
            'dedup_key' => "test:audit-first:{$dirtyRequest->id}",
            'metadata' => ['source' => 'test_fixture'],
            'created_by' => null,
            'occurred_at' => now(),
        ]);

        return [
            'migration' => $migration,
            'dirty_request' => $dirtyRequest,
            'leave_type' => $leaveType,
            'submit_employee' => $submitEmployee,
        ];
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/'.self::RECONCILIATION_MIGRATION);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function invokeMigration(Migration $migration, string $method): void
    {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }

    private function reservationNet(LeaveRequest $leaveRequest): int
    {
        return (int) DB::table('leave_balance_reservation_events')
            ->where('leave_request_id', $leaveRequest->id)
            ->sum('amount');
    }

    private function maintenanceLocksHeldByCurrentConnection(): int
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM pg_locks AS locks
JOIN pg_class AS relations ON relations.oid = locks.relation
WHERE locks.pid = pg_backend_pid()
  AND locks.granted = TRUE
  AND (
      (relations.relname IN ('ref_jenis_cuti', 'leave_requests') AND locks.mode = 'ShareLock')
      OR
      (relations.relname IN ('leave_balance_reservation_events', 'audit_logs') AND locks.mode = 'ShareRowExclusiveLock')
  )
SQL);

        return (int) $row->aggregate;
    }

    private function startWorker(string $fixture, array $payload): Process
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/'.$fixture),
            base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: 45);
        $process->start();

        return $process;
    }

    /** @param list<Process> $processes */
    private function stopWorkers(array $processes): void
    {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    private function waitForFile(
        string $path,
        int $timeoutMilliseconds = self::WORKER_READY_TIMEOUT_MILLISECONDS,
    ): bool {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            clearstatcache(true, $path);

            if (File::exists($path)) {
                return true;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return File::exists($path);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /** Menahan migrate:fresh kecuali jalur destruktif memakai database test standar. */
    private function requireStandardDestructiveTestDatabase(): void
    {
        $optIn = $_SERVER[self::DESTRUCTIVE_MIGRATION_OPT_IN]
            ?? $_ENV[self::DESTRUCTIVE_MIGRATION_OPT_IN]
            ?? getenv(self::DESTRUCTIVE_MIGRATION_OPT_IN);

        if ($optIn !== 'true') {
            $this->markTestSkipped('Set SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true untuk menjalankan concurrency rekonsiliasi reservasi.');
        }

        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing' || $driver !== 'pgsql' || $database !== self::STANDARD_TEST_DATABASE) {
            $this->fail(sprintf(
                'Concurrency rekonsiliasi reservasi ditolak: wajib APP_ENV=testing, driver pgsql, dan database %s; aktual environment=%s, driver=%s, database=%s.',
                self::STANDARD_TEST_DATABASE,
                $environment,
                $driver,
                $database,
            ));
        }
    }
}
