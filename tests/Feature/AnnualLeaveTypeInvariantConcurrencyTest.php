<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\RefJenisCuti;
use Illuminate\Database\Migrations\Migration;
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
use Throwable;

/** Membuktikan gate invariant tahunan tidak membentuk siklus lock dengan writer PostgreSQL. */
#[Group('guarded-destructive')]
class AnnualLeaveTypeInvariantConcurrencyTest extends TestCase
{
    use GuardsDestructiveMigrationTestEnvironment;

    private const DESTRUCTIVE_MIGRATION_OPT_IN = 'SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS';

    private const STANDARD_TEST_DATABASE = 'simpeg_test';

    /** Memberi ruang bootstrap worker pada bind mount Podman tanpa melonggarkan timeout proses. */
    private const WORKER_READY_TIMEOUT_MILLISECONDS = 30_000;

    private const MIGRATION = '2026_08_23_000001_enforce_annual_leave_type_balance_flag.php';

    public function test_gate_nowait_menghindari_deadlock_writer_row_exclusive_lalu_retry_bersih(): void
    {
        $fixture = $this->dirtyFixture();
        $directory = storage_path('framework/testing/annual-invariant-deadlock-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];

        try {
            $writer = $this->startWorker('AnnualLeaveTypeWriterWorker.php', [
                'mode' => 'interleave',
                'row_id' => $fixture['annual']->id,
                'updated_name' => 'Cuti Tahunan Setelah Writer',
                'proceed' => $directory.'/writer-proceed',
                'ready' => $directory.'/writer-ready.json',
                'result' => $directory.'/writer-result.json',
            ]);
            $processes[] = $writer;
            $this->assertTrue($this->waitForFile($directory.'/writer-ready.json'));

            $migration = $this->startWorker('AnnualLeaveTypeInvariantMigrationWorker.php', [
                'attempt' => $directory.'/gate-attempt.json',
                'row_locked' => $directory.'/row-locked.json',
                'ready' => $directory.'/migration-ready.json',
                'result' => $directory.'/migration-result.json',
            ]);
            $processes[] = $migration;
            $this->assertTrue($this->waitForFile($directory.'/migration-ready.json'));
            $signal = $this->waitForAnyFile([
                $directory.'/gate-attempt.json',
                $directory.'/row-locked.json',
            ]);
            $this->assertNotNull($signal, 'Migration tidak mencapai gate ataupun row-lock handshake.');

            File::put($directory.'/writer-proceed', 'commit');
            $writer->wait();
            $migration->wait();
            $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
            $this->assertTrue($migration->isSuccessful(), $migration->getErrorOutput());
            $this->assertTrue(
                File::exists($directory.'/gate-attempt.json'),
                'Writer hanya boleh dilepas setelah percobaan AccessExclusive NOWAIT benar-benar teramati.',
            );
            $writerResult = $this->readJson($directory.'/writer-result.json');
            $migrationResult = $this->readJson($directory.'/migration-result.json');
            $gateState = $this->readJson($directory.'/gate-attempt.json');
            $this->assertTrue((bool) ($writerResult['ok'] ?? false), json_encode($writerResult));
            $this->assertTrue((bool) ($migrationResult['ok'] ?? false), json_encode($migrationResult));
            $this->assertGreaterThanOrEqual(2, (int) ($gateState['count'] ?? 0));
            $this->assertSame('Cuti Tahunan Setelah Writer', $fixture['annual']->fresh()->nama);
            $this->assertTrue((bool) $fixture['annual']->fresh()->mengurangi_saldo_tahunan);
            $this->assertSame(1, AuditLog::query()
                ->where('event', 'CONFIG_UPDATE')
                ->where('auditable_type', 'RefJenisCuti')
                ->where('auditable_id', $fixture['annual']->id)
                ->count());
            $this->assertTrue($this->annualFlagConstraintExists());
        } finally {
            File::put($directory.'/writer-proceed', 'cleanup');
            $this->stopWorkers($processes);
            File::deleteDirectory($directory);
        }
    }

    public function test_retry_gate_habis_di_savepoint_tanpa_state_parsial_lalu_retry_bersih_sukses(): void
    {
        $fixture = $this->dirtyFixture();
        $directory = storage_path('framework/testing/annual-invariant-exhaustion-'.Str::uuid());
        File::ensureDirectoryExists($directory);
        $processes = [];

        try {
            $blocker = $this->startWorker('AnnualLeaveTypeWriterWorker.php', [
                'mode' => 'hold',
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
                $failure = null;

                try {
                    $this->invokeMigration($fixture['migration'], 'up');
                } catch (Throwable $exception) {
                    $failure = $exception;
                }

                $this->assertNotNull($failure, 'Migration wajib gagal tertutup setelah retry gate habis.');
                $this->assertInstanceOf(RuntimeException::class, $failure);
                $this->assertStringContainsString('setelah 80 percobaan', $failure->getMessage());
                $this->assertLessThan(3.0, microtime(true) - $startedAt);
                $this->assertSame(1, DB::transactionLevel());
                $this->assertFalse((bool) $fixture['annual']->fresh()->mengurangi_saldo_tahunan);
                $this->assertDatabaseCount('audit_logs', 0);
                $this->assertFalse($this->annualFlagConstraintExists());
                $this->assertSame(0, $this->maintenanceLocksHeldByCurrentConnection());

                File::put($directory.'/blocker-release', 'commit');
                $blocker->wait();
                $this->assertTrue($blocker->isSuccessful(), $blocker->getErrorOutput());
                $this->assertTrue((bool) ($this->readJson($directory.'/blocker-result.json')['ok'] ?? false));

                $this->invokeMigration($fixture['migration'], 'up');
                DB::commit();
            } catch (Throwable $exception) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }

                throw $exception;
            }

            $this->assertTrue((bool) $fixture['annual']->fresh()->mengurangi_saldo_tahunan);
            $this->assertSame(1, AuditLog::query()
                ->where('event', 'CONFIG_UPDATE')
                ->where('auditable_type', 'RefJenisCuti')
                ->where('auditable_id', $fixture['annual']->id)
                ->count());
            $this->assertTrue($this->annualFlagConstraintExists());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            File::put($directory.'/blocker-release', 'cleanup');
            $this->stopWorkers($processes);
            File::deleteDirectory($directory);
        }
    }

    /** @return array{migration:Migration,annual:RefJenisCuti} */
    private function dirtyFixture(): array
    {
        $this->requireStandardDestructiveTestDatabase();
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        $migration = $this->migration();
        $this->invokeMigration($migration, 'down');
        $annual = RefJenisCuti::query()->create([
            'nama' => 'Cuti Tahunan Dirty Concurrency',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);

        return ['migration' => $migration, 'annual' => $annual];
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    private function invokeMigration(Migration $migration, string $method): void
    {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }

    private function annualFlagConstraintExists(): bool
    {
        return DB::table('pg_constraint')
            ->where('conname', 'ref_jenis_cuti_annual_balance_flag_check')
            ->exists();
    }

    private function maintenanceLocksHeldByCurrentConnection(): int
    {
        $row = DB::selectOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM pg_locks AS locks
JOIN pg_class AS relations ON relations.oid = locks.relation
WHERE locks.pid = pg_backend_pid()
  AND locks.granted = TRUE
  AND relations.relname = 'ref_jenis_cuti'
  AND locks.mode IN ('AccessExclusiveLock', 'RowExclusiveLock', 'RowShareLock')
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
        return $this->waitForAnyFile([$path], $timeoutMilliseconds) !== null;
    }

    /** @param list<string> $paths */
    private function waitForAnyFile(array $paths, int $timeoutMilliseconds = 10_000): ?string
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            foreach ($paths as $path) {
                clearstatcache(true, $path);

                if (File::exists($path)) {
                    return $path;
                }
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return null;
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
            $this->markTestSkipped('Set SIMPEG_ALLOW_DESTRUCTIVE_MIGRATION_TESTS=true untuk menjalankan concurrency invariant tahunan.');
        }

        $environment = app()->environment();
        $driver = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if ($environment !== 'testing' || $driver !== 'pgsql' || $database !== self::STANDARD_TEST_DATABASE) {
            $this->fail(sprintf(
                'Concurrency invariant tahunan ditolak: wajib APP_ENV=testing, driver pgsql, dan database %s; aktual environment=%s, driver=%s, database=%s.',
                self::STANDARD_TEST_DATABASE,
                $environment,
                $driver,
                $database,
            ));
        }
    }
}
