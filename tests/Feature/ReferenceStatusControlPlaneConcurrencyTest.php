<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('serial')]
class ReferenceStatusControlPlaneConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Race control-plane status wajib diuji pada PostgreSQL.');
        }

        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        parent::tearDown();
    }

    public function test_insert_fk_dan_reklasifikasi_status_diserialisasi_oleh_row_lock(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create();
        $status = RefStatusPegawai::create([
            'kode' => 'CUSTOM_RACE',
            'nama' => 'Custom Race',
            'kelompok' => 'Nonaktif',
        ]);
        $paths = $this->racePaths();
        $usageWorker = $this->worker([
            'mode' => 'insert_usage',
            'employee_id' => $employee->id,
            'status_id' => $status->id,
            'ready' => $paths['usage_ready'],
            'inserted' => $paths['usage_inserted'],
            'release_usage' => $paths['release_usage'],
            'result' => $paths['usage_result'],
        ]);
        $updateWorker = $this->worker([
            'mode' => 'update_classification',
            'actor_id' => $actor->id,
            'status_id' => $status->id,
            'ready' => $paths['update_ready'],
            'result' => $paths['update_result'],
        ]);

        try {
            $usageWorker->start();
            $this->assertTrue($this->waitFor($paths['usage_inserted'], 30_000));

            $updateWorker->start();
            $this->assertTrue($this->waitFor($paths['update_ready'], 30_000));
            $updatePid = (int) File::get($paths['update_ready']);

            $this->assertTrue(
                $this->waitForLock($updatePid, 5_000),
                'Updater wajib menunggu key-share lock FK sebelum memeriksa pemakaian dan menyimpan klasifikasi.',
            );
            $this->assertFalse(File::exists($paths['update_result']));

            File::put($paths['release_usage'], 'release');
            $usageWorker->wait();
            $updateWorker->wait();

            $this->assertTrue($usageWorker->isSuccessful(), $usageWorker->getErrorOutput());
            $this->assertTrue($updateWorker->isSuccessful(), $updateWorker->getErrorOutput());

            $updateResult = json_decode(File::get($paths['update_result']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($updateResult['ok']);
            $this->assertSame(ValidationException::class, $updateResult['class']);
            $this->assertArrayHasKey('kelompok', $updateResult['errors']);
            $this->assertSame('Nonaktif', $status->refresh()->kelompok);
            $this->assertDatabaseHas('employee_status_transitions', [
                'status_pegawai_id' => $status->id,
            ]);
        } finally {
            File::put($paths['release_usage'], 'release');

            foreach ([$usageWorker, $updateWorker] as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
    }

    /** @return array<string, string> */
    private function racePaths(): array
    {
        $directory = storage_path('framework/testing/reference-status-race-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);

        return [
            'usage_ready' => $directory.'/usage-ready',
            'usage_inserted' => $directory.'/usage-inserted',
            'release_usage' => $directory.'/release-usage',
            'usage_result' => $directory.'/usage-result.json',
            'update_ready' => $directory.'/update-ready',
            'update_result' => $directory.'/update-result.json',
        ];
    }

    /** @param array<string, string> $input */
    private function worker(array $input): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/ReferenceStatusControlPlaneRaceWorker.php'),
            base64_encode(json_encode($input, JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: 60);
    }

    private function waitFor(string $path, int $timeoutMilliseconds): bool
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

    private function waitForLock(int $pid, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            $isWaiting = (bool) DB::scalar(
                "select exists(select 1 from pg_stat_activity where pid = ? and wait_event_type = 'Lock')",
                [$pid],
            );
            if ($isWaiting) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
