<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeMilestone;
use App\Models\RankHistory;
use App\Models\User;
use App\Services\Employees\TmtCalculatorService;
use Database\Seeders\EwsConfigSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

class EwsConfigLockOrderConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');

        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Urutan lock konfigurasi EWS wajib diuji pada PostgreSQL.');
        }

        parent::setUp();
        $this->seed([ReferenceSeeder::class, EwsConfigSeeder::class]);
    }

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        if ($this->app !== null) {
            $this->kosongkanAuditSebelumPenurunanMigrasi();
        }

        parent::tearDown();
    }

    /**
     * Perubahan konfigurasi harus menunggu lock pegawai sebelum menyentuh milestone.
     * Jika urutannya dibalik, writer TMT yang memegang pegawai akan membentuk deadlock.
     */
    public function test_config_change_uses_employee_then_milestone_lock_order(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        RankHistory::create([
            'employee_id' => $employee->id,
            'tmt_pangkat' => '2020-01-01',
            'golongan' => 'III/a',
            'pangkat' => 'Penata Muda',
        ]);
        app(TmtCalculatorService::class)->syncForEmployee($employee);

        $oldMilestone = EmployeeMilestone::query()
            ->where('employee_id', $employee->id)
            ->where('type', EmployeeMilestone::TYPE_KENAIKAN_PANGKAT)
            ->where('is_active', true)
            ->sole();
        $paths = $this->racePaths();
        $process = $this->worker([
            'actor_id' => $actor->id,
            ...$paths,
        ]);

        $parentError = null;
        DB::beginTransaction();

        try {
            Employee::query()
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $process->start();
            $this->assertTrue($this->tungguFile($paths['booted'], 30_000), 'Worker EWS gagal boot.');
            $this->assertTrue($this->tungguFile($paths['ready'], 30_000), 'Worker EWS tidak menulis backend PID.');
            $workerPid = (int) File::get($paths['ready']);
            $this->assertTrue(
                $this->tungguWorkerTerblokir($workerPid, 30_000),
                'Worker EWS harus menunggu lock pegawai yang dipegang writer TMT.',
            );

            // Writer TMT menyentuh milestone setelah pegawai. Query ini tidak boleh
            // terhalang oleh action konfigurasi yang masih menunggu pegawai.
            EmployeeMilestone::query()
                ->whereKey($oldMilestone->id)
                ->lockForUpdate()
                ->firstOrFail();

            DB::commit();
        } catch (Throwable $exception) {
            $parentError = $exception;
            DB::rollBack();
        }

        $process->wait();
        $diagnostic = $this->diagnostic($process, $paths);

        $this->assertNull($parentError, 'Writer TMT mengalami deadlock: '.$parentError?->getMessage().'. '.$diagnostic);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().'. '.$diagnostic);
        $this->assertTrue($this->tungguFile($paths['result'], 1_000), 'Worker wajib menulis hasil. '.$diagnostic);

        $outcome = json_decode(File::get($paths['result']), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($outcome['ok'], ($outcome['message'] ?? 'Action konfigurasi gagal.').' '.$diagnostic);

        $oldMilestone->refresh();
        $this->assertFalse($oldMilestone->is_active);
        $this->assertDatabaseHas('employee_milestones', [
            'employee_id' => $employee->id,
            'type' => EmployeeMilestone::TYPE_KENAIKAN_PANGKAT,
            'is_active' => true,
        ]);
    }

    /** @return array{booted:string, ready:string, stage:string, result:string} */
    private function racePaths(): array
    {
        $directory = storage_path('framework/testing/ews-config-lock-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);

        return [
            'booted' => $directory.'/booted',
            'ready' => $directory.'/ready',
            'stage' => $directory.'/stage',
            'result' => $directory.'/result.json',
        ];
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/EwsConfigLockOrderWorker.php'),
            base64_encode(json_encode($input, JSON_THROW_ON_ERROR)),
        ], base_path(), timeout: 90);
    }

    private function tungguFile(string $path, int $timeoutMilliseconds): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            clearstatcache(true, $path);

            if (File::exists($path)) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        clearstatcache(true, $path);

        return File::exists($path);
    }

    private function tungguWorkerTerblokir(int $workerPid, int $timeoutMilliseconds): bool
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

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** @param array{booted:string, ready:string, stage:string, result:string} $paths */
    private function diagnostic(Process $process, array $paths): string
    {
        return json_encode([
            'stage' => File::exists($paths['stage']) ? File::get($paths['stage']) : null,
            'result' => File::exists($paths['result']) ? File::get($paths['result']) : null,
            'stderr' => trim($process->getErrorOutput()),
            'stdout' => trim($process->getOutput()),
        ], JSON_THROW_ON_ERROR);
    }

    private function kosongkanAuditSebelumPenurunanMigrasi(): void
    {
        DB::unprepared('drop trigger if exists audit_logs_append_only on audit_logs;');
        DB::unprepared('drop trigger if exists audit_logs_append_only_truncate on audit_logs;');
        DB::table('audit_logs')->delete();
    }
}
