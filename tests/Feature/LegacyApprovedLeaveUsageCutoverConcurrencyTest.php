<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\RefJenisCuti;
use App\Models\RefJenisPegawai;
use App\Support\Cuti\BackfillLegacyApprovedLeaveUsage;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Concerns\GuardsDestructiveMigrationTestEnvironment;
use Tests\TestCase;

/** Memverifikasi gate maintenance cutover pada PostgreSQL nyata tanpa mengubah approval flow. */
#[Group('guarded-destructive')]
class LegacyApprovedLeaveUsageCutoverConcurrencyTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;

    private const ADVISORY_KEY = 500230011;

    private const DESTRUCTIVE_MIGRATION_OPT_IN = 'SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS';

    private const STANDARD_TEST_DATABASE = 'simpeg_test';

    /** Memberi ruang bootstrap worker pada bind mount Podman tanpa melonggarkan timeout proses. */
    private const WORKER_READY_TIMEOUT_MILLISECONDS = 30_000;

    public function test_cutover_tidak_deadlock_dengan_submit_employee_first(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Carbon::setTestNow('2026-08-23 09:00:00');
        $directory = storage_path('framework/testing/legacy-cutover-submit-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];

        try {
            $leaveType = RefJenisCuti::query()->create([
                'code' => 'sakit-submit-lock',
                'nama' => 'Cuti Sakit Submit Lock',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ]);
            $legacyEmployee = Employee::factory()->create();
            $legacyRequestId = $this->insertRequest($legacyEmployee, $leaveType, 'disetujui');
            $submitEmployee = Employee::factory()->create();
            $submittedRequestId = (string) Str::uuid();

            $submit = $this->startWorker('LegacyApprovedLeaveUsageInterleavingWorker.php', [
                'mode' => 'submit',
                'employee_id' => $submitEmployee->id,
                'leave_type_id' => $leaveType->id,
                'leave_request_id' => $submittedRequestId,
                'ready' => $directory.'/submit-ready.json',
                'continue' => $directory.'/submit-continue',
                'result' => $directory.'/submit-result.json',
            ]);
            $processes[] = $submit;
            $this->assertTrue($this->waitForFile($directory.'/submit-ready.json'));

            $cutover = $this->startWorker('LegacyApprovedLeaveUsageCutoverWorker.php', [
                'ready' => $directory.'/cutover-ready.json',
                'result' => $directory.'/cutover-result.json',
                'attempt' => $directory.'/cutover-attempt.json',
                'observe_query' => 'lock table employees in exclusive mode',
            ]);
            $processes[] = $cutover;
            $this->assertTrue($this->waitForFile($directory.'/cutover-ready.json'));
            $this->assertTrue(
                $this->waitForAttemptCount($directory.'/cutover-attempt.json', 2),
                'Cutover wajib membuktikan retry gate employee saat submit masih menahan row employee.',
            );
            File::put($directory.'/submit-continue', 'continue');

            $submit->wait();
            $cutover->wait();
            $this->assertTrue($submit->isSuccessful(), $submit->getErrorOutput());
            $this->assertTrue($cutover->isSuccessful(), $cutover->getErrorOutput());
            $submitResult = $this->readJson($directory.'/submit-result.json');
            $cutoverResult = $this->readJson($directory.'/cutover-result.json');
            $this->assertTrue((bool) ($submitResult['ok'] ?? false), json_encode($submitResult));
            $this->assertTrue((bool) ($cutoverResult['ok'] ?? false), json_encode($cutoverResult));
            $this->assertDatabaseHas('leave_usage_records', ['leave_request_id' => $legacyRequestId]);
            $this->assertDatabaseHas('leave_requests', [
                'id' => $submittedRequestId,
                'status' => 'menunggu_approval',
            ]);
            $this->assertDatabaseMissing('leave_usage_records', ['leave_request_id' => $submittedRequestId]);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            File::deleteDirectory($directory);
            Carbon::setTestNow();
        }
    }

    public function test_cutover_tidak_deadlock_dengan_approval_request_first(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Carbon::setTestNow('2026-08-23 09:00:00');
        $directory = storage_path('framework/testing/legacy-cutover-approve-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];

        try {
            $leaveType = RefJenisCuti::query()->create([
                'code' => 'sakit-approve-lock',
                'nama' => 'Cuti Sakit Approval Lock',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ]);
            $employee = Employee::factory()->create();
            $requestId = $this->insertRequest($employee, $leaveType, 'menunggu_approval');
            DB::table('leave_request_steps')->insert([
                'id' => (string) Str::uuid(),
                'leave_request_id' => $requestId,
                'step_order' => 1,
                'step_type' => 'pybmc',
                'role_label' => 'PYBMC',
                'approver_employee_id' => Employee::factory()->create()->id,
                'status' => 'active',
                'is_final' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $approval = $this->startWorker('LegacyApprovedLeaveUsageInterleavingWorker.php', [
                'mode' => 'approve',
                'employee_id' => $employee->id,
                'leave_request_id' => $requestId,
                'ready' => $directory.'/approval-ready.json',
                'continue' => $directory.'/approval-continue',
                'result' => $directory.'/approval-result.json',
            ]);
            $processes[] = $approval;
            $this->assertTrue($this->waitForFile($directory.'/approval-ready.json'));

            $cutover = $this->startWorker('LegacyApprovedLeaveUsageCutoverWorker.php', [
                'ready' => $directory.'/cutover-ready.json',
                'result' => $directory.'/cutover-result.json',
                'attempt' => $directory.'/cutover-attempt.json',
                'observe_query' => 'lock table leave_requests in exclusive mode',
            ]);
            $processes[] = $cutover;
            $this->assertTrue($this->waitForFile($directory.'/cutover-ready.json'));
            $this->assertTrue(
                $this->waitForAttemptCount($directory.'/cutover-attempt.json', 2),
                'Cutover wajib membuktikan retry gate request saat approval masih menahan row request.',
            );
            File::put($directory.'/approval-continue', 'continue');

            $approval->wait();
            $cutover->wait();
            $this->assertTrue($approval->isSuccessful(), $approval->getErrorOutput());
            $this->assertTrue($cutover->isSuccessful(), $cutover->getErrorOutput());
            $approvalResult = $this->readJson($directory.'/approval-result.json');
            $cutoverResult = $this->readJson($directory.'/cutover-result.json');
            $this->assertTrue((bool) ($approvalResult['ok'] ?? false), json_encode($approvalResult));
            $this->assertTrue((bool) ($cutoverResult['ok'] ?? false), json_encode($cutoverResult));
            $this->assertDatabaseHas('leave_requests', ['id' => $requestId, 'status' => 'disetujui']);
            $this->assertDatabaseHas('leave_usage_records', ['leave_request_id' => $requestId]);
            $this->assertSame(1, DB::table('leave_usage_records')->where('leave_request_id', $requestId)->count());
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            File::deleteDirectory($directory);
            Carbon::setTestNow();
        }
    }

    public function test_cutover_menunggu_mutasi_aktif_dan_memblokir_mutasi_baru_tanpa_siklus(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Carbon::setTestNow('2026-08-23 09:00:00');
        $directory = storage_path('framework/testing/legacy-cutover-lock-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];
        $advisoryHeld = false;

        try {
            $leaveType = RefJenisCuti::query()->create([
                'code' => 'sakit-lock',
                'nama' => 'Cuti Sakit Cutover Lock',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ]);
            $employee = Employee::factory()->create();
            $requestId = (string) Str::uuid();
            DB::table('leave_requests')->insert([
                'id' => $requestId,
                'employee_id' => $employee->id,
                'jenis_cuti_id' => $leaveType->id,
                'tanggal_mulai' => '2026-04-06',
                'tanggal_selesai' => '2026-04-06',
                'jumlah_hari_kerja' => 1,
                'alasan' => 'Fixture lock cutover.',
                'status' => 'disetujui',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::unprepared(sprintf(<<<'SQL'
CREATE OR REPLACE FUNCTION test_pause_legacy_backfill() RETURNS trigger AS $$
BEGIN
    PERFORM pg_advisory_xact_lock(%d);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER test_pause_legacy_backfill
BEFORE INSERT ON leave_usage_records
FOR EACH ROW EXECUTE FUNCTION test_pause_legacy_backfill();
SQL, self::ADVISORY_KEY));
            DB::select('SELECT pg_advisory_lock(?)', [self::ADVISORY_KEY]);
            $advisoryHeld = true;

            $active = $this->startWorker('LegacyApprovedLeaveUsageEmployeeLockWorker.php', [
                'mode' => 'hold',
                'employee_id' => $employee->id,
                'ready' => $directory.'/active-ready.json',
                'release' => $directory.'/active-release',
                'result' => $directory.'/active-result.json',
            ]);
            $processes[] = $active;
            $this->assertTrue($this->waitForFile($directory.'/active-ready.json'));

            $cutover = $this->startWorker('LegacyApprovedLeaveUsageCutoverWorker.php', [
                'ready' => $directory.'/cutover-ready.json',
                'result' => $directory.'/cutover-result.json',
                'attempt' => $directory.'/cutover-attempt.json',
                'observe_query' => 'lock table employees in exclusive mode',
            ]);
            $processes[] = $cutover;
            $this->assertTrue($this->waitForFile($directory.'/cutover-ready.json'));
            $cutoverPid = (int) $this->readJson($directory.'/cutover-ready.json')['pid'];

            $this->assertTrue(
                $this->waitForAttemptCount($directory.'/cutover-attempt.json', 2),
                'Cutover wajib retry sebelum mutation aktif dilepas.',
            );
            $this->assertFalse($this->isWaitingAdvisory($cutoverPid), 'Cutover belum boleh mencapai INSERT selama mutation aktif.');

            File::put($directory.'/active-release', 'commit');
            $active->wait();
            $this->assertTrue($active->isSuccessful(), $active->getErrorOutput());
            $this->assertTrue(($this->readJson($directory.'/active-result.json')['ok'] ?? false) === true);
            $this->assertTrue($this->waitForAdvisoryWait($cutoverPid), 'Cutover harus mencapai INSERT setelah mutation aktif selesai.');

            $probe = $this->startWorker('LegacyApprovedLeaveUsageEmployeeLockWorker.php', [
                'mode' => 'probe',
                'employee_id' => $employee->id,
                'result' => $directory.'/probe-result.json',
            ]);
            $processes[] = $probe;
            $probe->wait();
            $this->assertTrue($probe->isSuccessful(), $probe->getErrorOutput());
            $probeResult = $this->readJson($directory.'/probe-result.json');
            $this->assertFalse((bool) ($probeResult['ok'] ?? true), 'Mutation baru wajib diblokir oleh gate maintenance.');
            $this->assertSame('55P03', $probeResult['code'] ?? null, json_encode($probeResult));

            DB::select('SELECT pg_advisory_unlock(?)', [self::ADVISORY_KEY]);
            $advisoryHeld = false;
            $cutover->wait();
            $this->assertTrue($cutover->isSuccessful(), $cutover->getErrorOutput());
            $cutoverResult = $this->readJson($directory.'/cutover-result.json');
            $this->assertTrue((bool) ($cutoverResult['ok'] ?? false), json_encode($cutoverResult));
            $this->assertDatabaseHas('leave_usage_records', ['leave_request_id' => $requestId]);
        } finally {
            if ($advisoryHeld) {
                DB::select('SELECT pg_advisory_unlock(?)', [self::ADVISORY_KEY]);
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS test_pause_legacy_backfill ON leave_usage_records;
DROP FUNCTION IF EXISTS test_pause_legacy_backfill();
SQL);
            File::deleteDirectory($directory);
            Carbon::setTestNow();
        }
    }

    public function test_retry_gate_habis_di_savepoint_gagal_tertutup_dan_retry_bersih_berhasil(): void
    {
        $this->requireStandardDestructiveTestDatabase();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        Carbon::setTestNow('2026-08-23 09:00:00');
        $directory = storage_path('framework/testing/legacy-cutover-exhaustion-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];

        try {
            $annual = RefJenisCuti::query()->create([
                'code' => 'tahunan',
                'nama' => 'Cuti Tahunan',
                'mengurangi_saldo_tahunan' => true,
                'khusus_pns' => false,
            ]);
            $sick = RefJenisCuti::query()->create([
                'code' => 'sakit-exhaustion-probe',
                'nama' => 'Cuti Sakit Exhaustion Probe',
                'mengurangi_saldo_tahunan' => false,
                'khusus_pns' => false,
            ]);
            $pns = RefJenisPegawai::query()->firstOrCreate(['nama' => 'PNS']);
            $employee = Employee::factory()->create(['jenis_pegawai_id' => $pns->id]);
            Appointment::query()->create([
                'employee_id' => $employee->id,
                'jenis_pengangkatan' => 'PNS',
                'tmt_pengangkatan' => '2020-01-01',
                'no_sk' => 'SK-EXHAUSTION',
                'tanggal_sk' => '2020-01-01',
            ]);
            $approvedRequestId = $this->insertRequest($employee, $annual, 'disetujui');
            $probeEmployee = Employee::factory()->create();
            $probeRequestId = (string) Str::uuid();

            $blocker = $this->startWorker('LegacyApprovedLeaveUsageEmployeeLockWorker.php', [
                'mode' => 'hold',
                'employee_id' => $employee->id,
                'ready' => $directory.'/blocker-ready.json',
                'release' => $directory.'/blocker-release',
                'result' => $directory.'/blocker-result.json',
            ]);
            $processes[] = $blocker;
            $this->assertTrue($this->waitForFile($directory.'/blocker-ready.json'));

            DB::beginTransaction();

            try {
                $startedAt = microtime(true);

                try {
                    app(BackfillLegacyApprovedLeaveUsage::class)->execute();
                    $this->fail('Cutover wajib berhenti setelah retry gate habis.');
                } catch (\RuntimeException $exception) {
                    $this->assertStringContainsString('setelah 80 percobaan', $exception->getMessage());
                }

                $this->assertLessThan(10.0, microtime(true) - $startedAt, 'Retry gate wajib bounded.');
                $this->assertSame(1, DB::transactionLevel(), 'Outer transaction wajib tetap aktif setelah rollback savepoint.');
                $this->assertDatabaseCount('leave_usage_records', 0);
                $this->assertDatabaseCount('leave_balance_ledger', 0);
                $this->assertDatabaseCount('leave_balances', 0);
                $this->assertSame(0, AuditLog::query()->where('auditable_type', 'LeaveUsageRecord')->count());

                File::put($directory.'/probe-continue', 'continue');
                $probe = $this->startWorker('LegacyApprovedLeaveUsageInterleavingWorker.php', [
                    'mode' => 'submit',
                    'employee_id' => $probeEmployee->id,
                    'leave_type_id' => $sick->id,
                    'leave_request_id' => $probeRequestId,
                    'ready' => $directory.'/probe-ready.json',
                    'continue' => $directory.'/probe-continue',
                    'result' => $directory.'/probe-result.json',
                ]);
                $processes[] = $probe;
                $probe->wait();
                $probeResult = $this->readJson($directory.'/probe-result.json');
                $this->assertTrue((bool) ($probeResult['ok'] ?? false), json_encode($probeResult));

                File::put($directory.'/blocker-release', 'commit');
                $blocker->wait();
                $this->assertTrue((bool) ($this->readJson($directory.'/blocker-result.json')['ok'] ?? false));

                $summary = app(BackfillLegacyApprovedLeaveUsage::class)->execute();
                $this->assertSame(['facts' => 1, 'employees_recalculated' => 1], $summary);
                DB::commit();
            } catch (\Throwable $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                throw $exception;
            }

            $this->assertDatabaseHas('leave_usage_records', ['leave_request_id' => $approvedRequestId]);
            $this->assertDatabaseHas('leave_requests', [
                'id' => $probeRequestId,
                'status' => 'menunggu_approval',
            ]);
            $this->assertSame(1, LeaveBalance::query()->where('employee_id', $employee->id)->count());
            $this->assertGreaterThanOrEqual(2, LeaveBalanceLedger::query()->where('employee_id', $employee->id)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            File::deleteDirectory($directory);
            Carbon::setTestNow();
        }
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

    private function waitForFile(
        string $path,
        int $timeoutMilliseconds = self::WORKER_READY_TIMEOUT_MILLISECONDS,
    ): bool {
        return $this->waitUntil(static function () use ($path): bool {
            clearstatcache(true, $path);

            return File::exists($path);
        }, $timeoutMilliseconds);
    }

    private function insertRequest(Employee $employee, RefJenisCuti $leaveType, string $status): string
    {
        $id = (string) Str::uuid();
        DB::table('leave_requests')->insert([
            'id' => $id,
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $leaveType->id,
            'tanggal_mulai' => '2026-04-06',
            'tanggal_selesai' => '2026-04-06',
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Fixture interleaving cutover.',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function waitForAttemptCount(string $path, int $minimum, int $timeoutMilliseconds = 5_000): bool
    {
        return $this->waitUntil(function () use ($path, $minimum): bool {
            clearstatcache(true, $path);

            if (! File::exists($path)) {
                return false;
            }

            try {
                return (int) ($this->readJson($path)['count'] ?? 0) >= $minimum;
            } catch (FileNotFoundException|JsonException) {
                // Replace atomik pada bind mount dapat membuka celah rename singkat; polling mengulanginya.
                return false;
            }
        }, $timeoutMilliseconds);
    }

    private function waitForAdvisoryWait(int $pid): bool
    {
        return $this->waitUntil(fn (): bool => $this->isWaitingAdvisory($pid));
    }

    private function isWaitingAdvisory(int $pid): bool
    {
        return DB::table('pg_locks')
            ->where('pid', $pid)
            ->where('locktype', 'advisory')
            ->where('granted', false)
            ->exists();
    }

    private function waitUntil(callable $condition, int $timeoutMilliseconds = 5_000): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            if ($condition()) {
                return true;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return $condition();
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
            $this->markTestSkipped('Set SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true untuk menjalankan concurrency cutover.');
        }

        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing' || $driver !== 'pgsql' || $database !== self::STANDARD_TEST_DATABASE) {
            $this->fail(sprintf(
                'Concurrency cutover ditolak: wajib APP_ENV=testing, driver pgsql, dan database %s; aktual environment=%s, driver=%s, database=%s.',
                self::STANDARD_TEST_DATABASE,
                $environment,
                $driver,
                $database,
            ));
        }
    }
}
