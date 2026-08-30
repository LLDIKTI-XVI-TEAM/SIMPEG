<?php

namespace Tests\Feature;

use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Actions\Employees\AssignSupervisorAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

#[Group('serial')]
class ApprovalChainConfigurationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private const GLOBAL_LOCK_KEY = 'simpeg.leave_chain_configuration';

    private ?string $raceDirectory = null;

    protected function setUp(): void
    {
        $driver = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');

        if ($driver !== 'pgsql') {
            $this->markTestSkipped('Serialisasi konfigurasi rantai approval wajib diuji pada PostgreSQL.');
        }

        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->raceDirectory !== null) {
            File::deleteDirectory($this->raceDirectory);
        }

        // Skip driver dilakukan sebelum Laravel boot agar SQLite tidak menjalankan migrasi yang sia-sia.
        // Pembersihan basis data hanya aman bila parent::setUp() sempat membentuk container aplikasi.
        if ($this->app !== null) {
            $this->kosongkanAuditSebelumPenurunanMigrasi();
        }

        parent::tearDown();
    }

    public function test_save_chain_menunggu_lock_global_sebelum_membuat_chain_baru(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagian->id]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);
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

    public function test_submit_menunggu_lock_konfigurasi_sebelum_mengunci_pemohon_dengan_uuid_approver_lebih_kecil(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagian = Employee::factory()->create(['id' => '00000000-0000-4000-8000-000000000010']);
        $pybmc = Employee::factory()->create(['id' => '00000000-0000-4000-8000-000000000020']);
        $employee = Employee::factory()->create([
            'id' => 'ffffffff-ffff-4fff-8fff-fffffffffff0',
            'kepala_bagian_id' => $kepalaBagian->id,
        ]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $kepalaBagian->id,
            'tanggal_mulai' => '2026-01-01',
            'tanggal_berakhir' => null,
        ]);
        $jenisCuti = RefJenisCuti::create([
            'nama' => 'Cuti Sakit Uji Urutan Lock',
            'code' => 'sakit_uji_urutan_lock',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => false,
        ]);
        $chain = LeaveApprovalChain::create([
            'employee_id' => $employee->id,
            'name' => 'Chain uji urutan lock submit',
            'effective_from' => '2026-01-01',
        ]);
        $chain->steps()->createMany([
            ['step_order' => 1, 'step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagian->id, 'is_final' => false],
            ['step_order' => 2, 'step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
        ]);
        $paths = $this->racePaths('submit');
        $process = $this->worker([
            'action' => 'submit',
            'booted' => $paths['booted'],
            'ready' => $paths['ready'],
            'stage' => $paths['stage'],
            'result' => $paths['result'],
            'actor_id' => $actor->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $jenisCuti->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-08',
        ]);

        $outcome = $this->jalankanSaatLockGlobalDitahan($process, $paths);

        $this->assertTrue($outcome['ok'], $outcome['message'] ?? 'Worker SubmitLeaveRequestAction gagal.');
        $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame(
            [$kepalaBagian->id, $pybmc->id],
            $leaveRequest->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
    }

    public function test_penugasan_lebih_dulu_membuat_save_stale_gagal_setelah_lock_dilepas(): void
    {
        $fixture = $this->buatFixtureChainDenganSnapshot();
        $paths = $this->racePaths('assignment-first');
        $process = $this->worker([
            'action' => 'save',
            'booted' => $paths['booted'],
            'ready' => $paths['ready'],
            'stage' => $paths['stage'],
            'result' => $paths['result'],
            'actor_id' => $fixture['actor']->id,
            'employee_id' => $fixture['employee']->id,
            'kepala_bagian_id' => $fixture['kepala_bagian_lama']->id,
            'pybmc_id' => $fixture['pybmc']->id,
        ]);

        $outcome = $this->jalankanDenganWriterPertama(
            fn () => $this->app->make(AssignSupervisorAction::class)->execute(
                $fixture['employee'],
                $fixture['kepala_bagian_baru']->id,
                today()->toDateString(),
            ),
            $process,
            $paths,
        );

        $this->assertFalse($outcome['ok']);
        $this->assertSame(\RuntimeException::class, $outcome['class']);
        $this->assertSame(
            'Approver pada tahap Kepala Bagian harus sama dengan Kepala Bagian efektif pegawai.',
            $outcome['message'],
        );
        $this->assertSame(1, LeaveApprovalChain::query()->where('employee_id', $fixture['employee']->id)->count());
        $this->assertSame(
            [$fixture['kepala_bagian_baru']->id, $fixture['pybmc']->id],
            $fixture['chain']->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(
            $fixture['snapshot'],
            $this->rawStepRows($fixture['leave']),
        );
        $this->assertSame(
            $fixture['chain_audit_count'],
            AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count(),
        );
        $this->assertSame(
            $fixture['kepala_bagian_baru']->id,
            Employee::query()->findOrFail($fixture['employee']->id)->currentSupervisor()?->kepala_bagian_id,
        );
    }

    public function test_save_lebih_dulu_disinkronkan_oleh_penugasan_setelah_lock_dilepas(): void
    {
        $fixture = $this->buatFixtureChainDenganSnapshot();
        $paths = $this->racePaths('save-first');
        $process = $this->worker([
            'action' => 'assignment',
            'booted' => $paths['booted'],
            'ready' => $paths['ready'],
            'stage' => $paths['stage'],
            'result' => $paths['result'],
            'actor_id' => $fixture['actor']->id,
            'employee_id' => $fixture['employee']->id,
            'kepala_bagian_id' => $fixture['kepala_bagian_baru']->id,
            'effective_date' => today()->toDateString(),
        ]);

        $outcome = $this->jalankanDenganWriterPertama(
            fn () => $this->app->make(SaveEmployeeApprovalChainAction::class)->execute(
                $fixture['employee'],
                [
                    ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $fixture['kepala_bagian_lama']->id, 'is_final' => false],
                    ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $fixture['pybmc']->id, 'is_final' => true],
                ],
                $fixture['actor'],
                'Save pertama sebelum pergantian Kepala Bagian.',
            ),
            $process,
            $paths,
        );

        $this->assertTrue($outcome['ok'], $outcome['message'] ?? 'Worker AssignSupervisorAction gagal.');
        $activeChain = LeaveApprovalChain::query()
            ->where('employee_id', $fixture['employee']->id)
            ->where('is_active', true)
            ->sole();

        $this->assertSame(2, LeaveApprovalChain::query()->where('employee_id', $fixture['employee']->id)->count());
        $this->assertSame(1, LeaveApprovalChain::query()->where('employee_id', $fixture['employee']->id)->where('is_active', true)->count());
        $this->assertSame(
            [$fixture['kepala_bagian_baru']->id, $fixture['pybmc']->id],
            $activeChain->steps()->orderBy('step_order')->pluck('approver_employee_id')->all(),
        );
        $this->assertSame(
            $fixture['snapshot'],
            $this->rawStepRows($fixture['leave']),
        );
        $this->assertSame(
            $fixture['kepala_bagian_baru']->id,
            Employee::query()->findOrFail($fixture['employee']->id)->currentSupervisor()?->kepala_bagian_id,
        );
    }

    /**
     * @param  array{booted:string, ready:string, stage:string, result:string}  $paths
     * @return array<string, mixed>
     */
    private function jalankanSaatLockGlobalDitahan(Process $process, array $paths): array
    {
        return $this->jalankanDenganWriterPertama(
            fn () => DB::select(
                'select pg_advisory_xact_lock(hashtextextended(?, 0))',
                [self::GLOBAL_LOCK_KEY],
            ),
            $process,
            $paths,
        );
    }

    /**
     * Menahan transaksi writer pertama sampai worker kedua siap memasuki Action, sehingga urutan
     * serialisasi dibuktikan dengan barrier file dan bukan asumsi waktu mulai proses.
     *
     * @param  callable(): mixed  $firstWriter
     * @param  array{booted:string, ready:string, stage:string, result:string}  $paths
     * @return array<string, mixed>
     */
    private function jalankanDenganWriterPertama(callable $firstWriter, Process $process, array $paths): array
    {
        DB::beginTransaction();

        try {
            $firstWriter();
            $process->start();
            $bootedTerlihat = $this->tungguFile($paths['booted'], 30_000);
            $readyTerlihat = $bootedTerlihat && $this->tungguFile($paths['ready'], 30_000);
            $selesaiSaatLockDitahan = $readyTerlihat && $this->tungguFile($paths['result'], 5_000);
            DB::commit();
        } catch (Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($process->isRunning()) {
                $process->stop();
            }

            throw $exception;
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
            'Operasi rantai approval tidak boleh selesai selama lock global masih dipegang.',
        );
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertTrue($this->tungguFile($paths['result'], 1_000), 'Worker wajib menulis hasil setelah lock dilepas.');

        return json_decode(File::get($paths['result']), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{
     *     actor:User,
     *     employee:Employee,
     *     kepala_bagian_lama:Employee,
     *     kepala_bagian_baru:Employee,
     *     pybmc:Employee,
     *     chain:LeaveApprovalChain,
     *     leave:LeaveRequest,
     *     snapshot:list<array<string, mixed>>,
     *     chain_audit_count:int
     * }
     */
    private function buatFixtureChainDenganSnapshot(): array
    {
        $actor = User::factory()->superAdmin()->create();
        $kepalaBagianLama = Employee::factory()->create();
        $kepalaBagianBaru = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $employee = Employee::factory()->create(['kepala_bagian_id' => $kepalaBagianLama->id]);
        SupervisorAssignment::create([
            'employee_id' => $employee->id,
            'kepala_bagian_id' => $kepalaBagianLama->id,
            'tanggal_mulai' => today()->subDay(),
            'tanggal_berakhir' => null,
        ]);
        $this->actingAs($actor);
        $chain = $this->app->make(SaveEmployeeApprovalChainAction::class)->execute(
            $employee,
            [
                ['step_type' => 'kepala_bagian', 'role_label' => 'Kepala Bagian', 'approver_employee_id' => $kepalaBagianLama->id, 'is_final' => false],
                ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
            ],
            $actor,
            'Chain awal untuk uji serialisasi penugasan.',
        );
        $jenisCuti = RefJenisCuti::query()->firstOrFail();
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => today()->addWeek()->toDateString(),
            'tanggal_selesai' => today()->addWeek()->toDateString(),
            'jumlah_hari_kerja' => 1,
            'alasan' => 'Snapshot tidak boleh berubah saat konfigurasi bersaing.',
            'status' => 'menunggu_approval',
        ]);
        $leave->steps()->createMany($chain->steps()->orderBy('step_order')->get()->map(fn ($step): array => [
            'step_order' => $step->step_order,
            'step_type' => $step->step_type,
            'role_label' => $step->role_label,
            'approver_employee_id' => $step->approver_employee_id,
            'status' => $step->step_order === 1 ? 'active' : 'pending',
            'is_final' => $step->is_final,
        ])->all());

        return [
            'actor' => $actor,
            'employee' => $employee,
            'kepala_bagian_lama' => $kepalaBagianLama,
            'kepala_bagian_baru' => $kepalaBagianBaru,
            'pybmc' => $pybmc,
            'chain' => $chain,
            'leave' => $leave,
            'snapshot' => $this->rawStepRows($leave),
            'chain_audit_count' => AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rawStepRows(LeaveRequest $leave): array
    {
        return $leave->steps()
            ->orderBy('step_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($step): array => $step->getRawOriginal())
            ->values()
            ->all();
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
