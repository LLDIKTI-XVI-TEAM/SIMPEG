<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ApprovalChainConfigurationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const GLOBAL_LOCK_KEY = 'simpeg.leave_chain_configuration';

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();

        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');

        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Serialisasi konfigurasi rantai approval wajib diuji pada PostgreSQL.');
        }
        $this->seed(ReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        $this->kosongkanAuditSebelumPenurunanMigrasi();
        parent::tearDown();
    }

    public function test_save_chain_menunggu_lock_global_sebelum_membuat_chain_baru(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $paths = $this->racePaths('save');
        $process = $this->worker([
            'action' => 'save',
            'booted' => $paths['booted'],
            'ready' => $paths['ready'],
            'stage' => $paths['stage'],
            'result' => $paths['result'],
            'actor_id' => $actor->id,
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'pybmc_id' => $pybmc->id,
        ]);

        $outcome = $this->jalankanSaatLockGlobalDitahan($process, $paths);

        $this->assertTrue($outcome['ok'], $outcome['message'] ?? 'Worker SaveAction gagal.');
        $chain = LeaveApprovalChain::query()
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->sole();
        $this->assertSame(
            [$kepalaBagian->id, $pybmc->id],
            $chain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
    }

    public function test_apply_global_menunggu_lock_global_sebelum_memindai_chain_aktif(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmcLama = Employee::factory()->create();
        $pybmcBaru = Employee::factory()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain sebelum serialisasi global',
            'effective_from' => today(),
        ]);
        $chain->steps()->createMany([
            ['step_order' => 1, 'step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
            ['step_order' => 2, 'step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmcLama->id, 'is_final' => true],
        ]);
        $paths = $this->racePaths('global');
        $process = $this->worker([
            'action' => 'global',
            'booted' => $paths['booted'],
            'ready' => $paths['ready'],
            'stage' => $paths['stage'],
            'result' => $paths['result'],
            'actor_id' => $actor->id,
            'approver_id' => $pybmcBaru->id,
        ]);

        $outcome = $this->jalankanSaatLockGlobalDitahan($process, $paths);

        $this->assertTrue($outcome['ok'], $outcome['message'] ?? 'Worker ApplyGlobal gagal.');
        $this->assertSame(
            $pybmcBaru->id,
            $chain->steps()->where('is_final', true)->sole()->approver_employee_id,
        );
        $this->assertSame($pybmcBaru->id, LeavePybmcGlobalConfig::query()->sole()->approver_employee_id);
        $this->assertSame($actor->id, AuditLog::query()->where('auditable_type', 'LeavePybmcGlobalConfig')->sole()->user_id);
    }

    /**
     * @param  array{booted:string, ready:string, stage:string, result:string}  $paths
     * @return array<string, mixed>
     */
    private function jalankanSaatLockGlobalDitahan(Process $process, array $paths): array
    {
        DB::beginTransaction();
        DB::select(
            'select pg_advisory_xact_lock(hashtextextended(?, 0))',
            [self::GLOBAL_LOCK_KEY],
        );

        try {
            $process->start();
            $bootedTerlihat = $this->tungguFile($paths['booted'], 30_000);
            $readyTerlihat = $bootedTerlihat && $this->tungguFile($paths['ready'], 30_000);
            $selesaiSaatLockDitahan = $readyTerlihat && $this->tungguFile($paths['result'], 5_000);
        } finally {
            DB::commit();
        }

        $process->wait();
        $diagnostic = json_encode([
            'stage' => File::exists($paths['stage']) ? File::get($paths['stage']) : null,
            'result' => File::exists($paths['result']) ? File::get($paths['result']) : null,
            'stderr' => trim($process->getErrorOutput()),
            'stdout' => trim($process->getOutput()),
        ], JSON_THROW_ON_ERROR);

        $this->assertTrue(
            $bootedTerlihat,
            'Worker wajib mencapai marker booted. Diagnostic: '.$diagnostic,
        );
        $this->assertTrue(
            $readyTerlihat,
            'Worker wajib menyelesaikan lookup fixture sebelum pengujian lock. Diagnostic: '.$diagnostic,
        );
        $this->assertFalse(
            $selesaiSaatLockDitahan,
            'Writer konfigurasi rantai tidak boleh selesai selama lock global masih dipegang.',
        );
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertTrue($this->tungguFile($paths['result'], 1_000), 'Worker wajib menulis hasil setelah lock dilepas.');

        return json_decode(File::get($paths['result']), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{booted:string, ready:string, stage:string, result:string}
     */
    private function racePaths(string $suffix): array
    {
        $directory = storage_path('framework/testing/approval-chain-lock-'.Str::uuid());
        $this->raceDirectory = $directory;
        File::ensureDirectoryExists($directory);

        return [
            'booted' => $directory.'/booted-'.$suffix,
            'ready' => $directory.'/ready-'.$suffix,
            'stage' => $directory.'/stage-'.$suffix,
            'result' => $directory.'/result-'.$suffix.'.json',
        ];
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/ApprovalChainConfigurationLockWorker.php'),
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

    private function kosongkanAuditSebelumPenurunanMigrasi(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('drop trigger if exists audit_logs_append_only on audit_logs;');
            DB::unprepared('drop trigger if exists audit_logs_append_only_truncate on audit_logs;');
        }

        DB::table('audit_logs')->delete();
    }
}
