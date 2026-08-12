<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmployeeEmailIdentityConstraintTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $raceDirectory = null;

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    /** Migrasi tidak boleh menebak pemilik identitas dengan menulis email sintetis. */
    public function test_unique_email_migration_fails_without_mutating_duplicate_identity_data(): void
    {
        DB::statement('DROP INDEX IF EXISTS employees_email_pribadi_unique');

        $first = Employee::factory()->create(['email_pribadi' => 'duplikat.migrasi@example.com']);
        $second = Employee::factory()->create(['email_pribadi' => 'alamat.lain@example.com']);
        DB::table('employees')->where('id', $second->id)->update([
            'email_pribadi' => 'DUPLIKAT.MIGRASI@example.com',
            'email' => 'DUPLIKAT.MIGRASI@example.com',
        ]);

        try {
            $migration = require database_path('migrations/2026_08_12_100000_add_email_pribadi_unique_to_employees_table.php');

            try {
                $migration->up();
                $this->fail('Migrasi harus berhenti ketika identitas email masih ambigu.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Bersihkan duplikat', $exception->getMessage());
                $this->assertStringNotContainsString('duplikat.migrasi@example.com', strtolower($exception->getMessage()));
            }

            $this->assertSame(
                'duplikat.migrasi@example.com',
                DB::table('employees')->where('id', $first->id)->value('email_pribadi'),
            );
            $this->assertSame(
                'DUPLIKAT.MIGRASI@example.com',
                DB::table('employees')->where('id', $second->id)->value('email_pribadi'),
            );
        } finally {
            DB::table('employees')->where('id', $second->id)->update([
                'email_pribadi' => 'alamat.lain@example.com',
                'email' => 'alamat.lain@example.com',
            ]);
            $this->restoreUniqueIndex();
        }
    }

    /** Lock tabel harus diambil sebelum pemeriksaan agar writer tidak masuk ke celah TOCTOU. */
    public function test_postgres_migration_blocks_writer_before_duplicate_check_and_returns_sanitized_failure(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Urutan lock migrasi identitas diverifikasi khusus pada PostgreSQL.');
        }

        DB::statement('DROP INDEX IF EXISTS employees_email_pribadi_unique');

        $first = Employee::factory()->create(['email_pribadi' => 'rahasia.kembar@example.com']);
        $second = Employee::factory()->create(['email_pribadi' => 'alamat.kedua@example.com']);
        DB::table('employees')->where('id', $second->id)->update([
            'email_pribadi' => 'RAHASIA.KEMBAR@example.com',
        ]);

        $paths = $this->racePaths();
        $process = $this->migrationWorker($paths);
        $workerBlocked = false;

        DB::beginTransaction();

        try {
            // UPDATE memperoleh ROW EXCLUSIVE sehingga SHARE lock migrasi harus menunggu.
            DB::table('employees')->where('id', $first->id)->update(['updated_at' => now()]);
            $process->start();
            $this->assertTrue($this->waitForFile($paths['pid'], 10_000), 'Worker migrasi gagal menulis PID backend.');

            $workerBlocked = $this->waitForWorkerLock((int) File::get($paths['pid']), 5_000);
        } finally {
            DB::rollBack();
        }

        $process->wait();

        try {
            $this->assertTrue(
                $workerBlocked,
                'Migrasi harus menunggu lock writer sebelum memeriksa duplikasi email.',
            );
            $this->assertTrue($this->waitForFile($paths['result'], 1_000), $process->getErrorOutput());

            $outcome = json_decode(File::get($paths['result']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($outcome['ok']);
            $this->assertStringContainsString('Bersihkan duplikat', $outcome['message']);
            $this->assertStringNotContainsString('rahasia.kembar@example.com', strtolower($outcome['message']));
            $this->assertSame(
                'rahasia.kembar@example.com',
                DB::table('employees')->where('id', $first->id)->value('email_pribadi'),
            );
            $this->assertSame(
                'RAHASIA.KEMBAR@example.com',
                DB::table('employees')->where('id', $second->id)->value('email_pribadi'),
            );
            $this->assertFalse($this->uniqueIndexExists());
        } finally {
            DB::table('employees')->where('id', $second->id)->update([
                'email_pribadi' => 'alamat.kedua@example.com',
            ]);
            $this->restoreUniqueIndex();
        }
    }

    public function test_duplicate_index_exception_is_sanitized_without_sensitive_previous_chain(): void
    {
        $migration = require database_path('migrations/2026_08_12_100000_add_email_pribadi_unique_to_employees_table.php');
        $method = new \ReflectionMethod($migration, 'rethrowIndexCreationFailure');
        $exception = $this->indexQueryException(
            '23505',
            'Key (lower(email_pribadi))=(rahasia.chain@example.com) already exists.',
        );

        try {
            $method->invoke($migration, $exception);
            $this->fail('Pelanggaran unique harus dipetakan menjadi pesan migrasi yang aman.');
        } catch (RuntimeException $sanitized) {
            $this->assertStringContainsString('Bersihkan duplikat', $sanitized->getMessage());
            $this->assertStringNotContainsString('rahasia.chain@example.com', strtolower($sanitized->getMessage()));
            $this->assertNull($sanitized->getPrevious());
        }
    }

    public function test_non_duplicate_index_exception_is_rethrown_unchanged(): void
    {
        $migration = require database_path('migrations/2026_08_12_100000_add_email_pribadi_unique_to_employees_table.php');
        $method = new \ReflectionMethod($migration, 'rethrowIndexCreationFailure');
        $exception = $this->indexQueryException('42P01', 'relation employees does not exist');

        try {
            $method->invoke($migration, $exception);
            $this->fail('Error DDL di luar duplikasi tidak boleh disamarkan.');
        } catch (QueryException $rethrown) {
            $this->assertSame($exception, $rethrown);
        }
    }

    /** @return array{pid:string,result:string} */
    private function racePaths(): array
    {
        $directory = storage_path('framework/testing/email-identity-migration-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);

        return [
            'pid' => $directory.'/pid',
            'result' => $directory.'/result.json',
        ];
    }

    /** @param array{pid:string,result:string} $paths */
    private function migrationWorker(array $paths): Process
    {
        $script = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $app = require $argv[1].'/bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $pid = Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid;
            file_put_contents($argv[2], (string) $pid);

            try {
                $migration = require $argv[4];
                $migration->up();
                $outcome = ['ok' => true, 'message' => null];
            } catch (Throwable $exception) {
                $outcome = ['ok' => false, 'message' => $exception->getMessage()];
            }

            file_put_contents($argv[3], json_encode($outcome, JSON_THROW_ON_ERROR));
            PHP;

        return new Process([
            PHP_BINARY,
            '-r',
            $script,
            base_path(),
            $paths['pid'],
            $paths['result'],
            database_path('migrations/2026_08_12_100000_add_email_pribadi_unique_to_employees_table.php'),
        ], base_path(), timeout: 30);
    }

    private function waitForFile(string $path, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            clearstatcache(true, $path);

            if (File::exists($path)) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return File::exists($path);
    }

    private function waitForWorkerLock(int $workerPid, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            $activity = DB::selectOne(
                'select wait_event_type from pg_stat_activity where pid = ?',
                [$workerPid],
            );

            if (($activity->wait_event_type ?? null) === 'Lock') {
                return true;
            }

            if ($this->waitForFile($this->racePathsResult(), 0)) {
                return false;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function racePathsResult(): string
    {
        return $this->raceDirectory.'/result.json';
    }

    private function restoreUniqueIndex(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS employees_email_pribadi_unique '
            .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
        );
    }

    private function uniqueIndexExists(): bool
    {
        return DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('indexname', 'employees_email_pribadi_unique')
            ->exists();
    }

    private function indexQueryException(string $sqlState, string $diagnostic): QueryException
    {
        $driverException = new \PDOException($diagnostic);
        $driverException->errorInfo = [$sqlState, null, $diagnostic];

        return new QueryException(
            'pgsql',
            'CREATE UNIQUE INDEX employees_email_pribadi_unique ON employees (LOWER(email_pribadi))',
            [],
            $driverException,
        );
    }
}
