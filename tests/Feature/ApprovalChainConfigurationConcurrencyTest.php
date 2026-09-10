<?php

namespace Tests\Feature;

use App\Actions\Auth\UpdateUserMappingAction;
use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\PreviewEmployeeApprovalChainsAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Actions\Employees\AssignSupervisorAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\LeaveRequest;
use App\Models\Permission;
use App\Models\RefJenisCuti;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\SupervisorAssignment;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
        $this->seed(RbacSeeder::class);
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

    public function test_apply_global_menunggu_lock_global_sebelum_memberi_revisi_dan_memindai_chain_aktif(): void
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
            ...$paths,
            'actor_id' => $actor->id,
            'approver_id' => $pybmcBaru->id,
        ]);

        $outcome = $this->jalankanDenganPerubahanSaatWorkerMenunggu(
            $process,
            $paths,
            fn () => app(ApplyGlobalPybmcAction::class)->execute($pybmcLama, $actor, 'Writer pertama di bawah lock.'),
        );

        $this->assertTrue($outcome['ok'], $outcome['message'] ?? 'Worker ApplyGlobal gagal.');
        $this->assertSame(
            $pybmcBaru->id,
            $chain->steps()->where('is_final', true)->sole()->approver_employee_id,
        );
        $configs = LeavePybmcGlobalConfig::query()->latestRevision()->get();
        $this->assertSame([2, 1], $configs->pluck('revision')->all());
        $this->assertSame([$pybmcBaru->id, $pybmcLama->id], $configs->pluck('approver_employee_id')->all());
        $audits = AuditLog::query()->where('auditable_type', 'LeavePybmcGlobalConfig')->get();
        $this->assertCount(2, $audits);
        $this->assertSame([$actor->id], $audits->pluck('user_id')->unique()->values()->all());
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
            'Approver tahap Atasan Langsung harus sesuai penugasan Atasan Langsung efektif pegawai.',
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

    public function test_save_menolak_target_yang_keluar_scope_saat_menunggu_lock(): void
    {
        $identity = Employee::factory()->create();
        $newSupervisor = Employee::factory()->create();
        $target = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        $actor = User::factory()->kepalaBagian()->create(['employee_id' => $identity->id]);
        $mutationActor = User::factory()->superAdmin()->create();
        $this->actingAs($mutationActor);
        $permission = Permission::query()->where('name', 'cuti.configure')->sole();
        Role::query()->where('name', 'kepala_bagian')->sole()->permissions()->syncWithoutDetaching([$permission->id]);
        SupervisorAssignment::create([
            'employee_id' => $target->id,
            'kepala_bagian_id' => $identity->id,
            'tanggal_mulai' => today()->subDay(),
        ]);
        $paths = $this->racePaths('scope-recheck');
        $process = $this->saveWorker($paths, $actor, $target, $identity, $pybmc);

        $outcome = $this->jalankanDenganPerubahanSaatWorkerMenunggu(
            $process,
            $paths,
            fn () => $this->app->make(AssignSupervisorAction::class)->execute(
                $target,
                $newSupervisor->id,
                today()->toDateString(),
            ),
        );

        $this->assertFalse($outcome['ok']);
        $this->assertSame(NotFoundHttpException::class, $outcome['class']);
        $this->assertSame(404, $outcome['status']);
        $this->assertDatabaseCount('leave_approval_chains', 0);
        $this->assertSame(0, AuditLog::query()->where('auditable_type', 'LeaveApprovalChain')->count());
    }

    public function test_save_menolak_target_yang_menjadi_nonaktif_saat_menunggu_lock(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $supervisor = Employee::factory()->create();
        $target = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::create([
            'employee_id' => $target->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay(),
        ]);
        $inactive = RefStatusPegawai::create([
            'kode' => 'NONAKTIF-RACE',
            'nama' => 'Nonaktif Race',
            'kelompok' => 'Nonaktif',
            'is_default' => false,
        ]);
        $paths = $this->racePaths('lifecycle-recheck');
        $oldChain = $this->buatChainExisting($target, $supervisor, $pybmc, $actor);
        $process = $this->saveWorker($paths, $actor, $target, $supervisor, $pybmc);

        $outcome = $this->jalankanDenganPerubahanSaatWorkerMenunggu(
            $process,
            $paths,
            fn () => $target->update(['status_pegawai_id' => $inactive->id, 'status_aktif' => 'Non-Aktif']),
        );

        $this->assertFalse($outcome['ok']);
        $this->assertSame(ValidationException::class, $outcome['class']);
        $this->assertSame(422, $outcome['status']);
        $this->assertSame([$oldChain->id], LeaveApprovalChain::query()->where('employee_id', $target->id)->pluck('id')->all());
        $this->assertTrue($oldChain->refresh()->is_active);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_save_menolak_permission_yang_dicabut_saat_menunggu_lock(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $supervisor = Employee::factory()->create();
        $target = Employee::factory()->create();
        $pybmc = Employee::factory()->create();
        SupervisorAssignment::create([
            'employee_id' => $target->id,
            'kepala_bagian_id' => $supervisor->id,
            'tanggal_mulai' => today()->subDay(),
        ]);
        $permission = Permission::query()->where('name', 'cuti.configure')->sole();
        $role = Role::query()->where('name', 'super_admin')->sole();
        $paths = $this->racePaths('permission-recheck');
        $oldChain = $this->buatChainExisting($target, $supervisor, $pybmc, $actor);
        $process = $this->saveWorker($paths, $actor, $target, $supervisor, $pybmc);

        $outcome = $this->jalankanDenganPerubahanSaatWorkerMenunggu(
            $process,
            $paths,
            fn () => $role->permissions()->detach($permission->id),
        );

        $this->assertFalse($outcome['ok']);
        $this->assertSame(HttpException::class, $outcome['class']);
        $this->assertSame(403, $outcome['status']);
        $this->assertSame([$oldChain->id], LeaveApprovalChain::query()->where('employee_id', $target->id)->pluck('id')->all());
        $this->assertTrue($oldChain->refresh()->is_active);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_batch_menunggu_config_lock_dengan_union_uuid_approver_lebih_kecil(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $supervisor = Employee::factory()->create(['id' => '00000000-0000-4000-8000-000000000010']);
        $pybmc = Employee::factory()->create(['id' => '00000000-0000-4000-8000-000000000020']);
        $target = Employee::factory()->create(['id' => 'ffffffff-ffff-4fff-8fff-fffffffffff0']);
        SupervisorAssignment::create(['employee_id' => $target->id, 'kepala_bagian_id' => $supervisor->id, 'tanggal_mulai' => today()->subDay()]);
        $draft = ['employee_ids' => [$target->id], 'mode' => 'missing_only', 'verifiers' => [], 'pybmc_mode' => 'custom', 'pybmc_employee_id' => $pybmc->id, 'reason' => null];
        $paths = $this->racePaths('batch-lock-order');
        $process = $this->batchWorker($paths, $actor, $draft);
        $outcome = $this->jalankanDenganPerubahanSaatWorkerMenunggu($process, $paths, fn () => null);
        $this->assertTrue($outcome['ok'], $outcome['message'] ?? 'Batch gagal.');
        $this->assertSame(1, $outcome['counts']['create']);
        $this->assertDatabaseCount('leave_approval_chains', 1);
    }

    #[DataProvider('batchCompetingWriters')]
    public function test_batch_membaca_ulang_state_setelah_writer_pertama_selesai(string $writer, int $status): void
    {
        $fixture = $this->buatFixtureChainDenganSnapshot();
        $actor = $fixture['actor'];
        $target = $fixture['employee'];
        if ($writer === 'scope') {
            $actor = User::factory()->kepalaBagian()->create(['employee_id' => $fixture['kepala_bagian_lama']->id]);
            Role::query()->where('name', 'kepala_bagian')->sole()->permissions()->syncWithoutDetaching([Permission::query()->where('name', 'cuti.configure')->sole()->id]);
        }
        $newPybmc = Employee::factory()->create();
        $draft = ['employee_ids' => [$target->id], 'mode' => 'replace', 'verifiers' => [], 'pybmc_mode' => 'custom', 'pybmc_employee_id' => $newPybmc->id, 'reason' => null];
        $paths = $this->racePaths('batch-'.$writer);
        $process = $this->batchWorker($paths, $actor, $draft);
        $snapshotAfterFirst = null;
        $outcome = $this->jalankanDenganPerubahanSaatWorkerMenunggu($process, $paths, function () use ($writer, $fixture, $actor, $target, $newPybmc, &$snapshotAfterFirst): void {
            match ($writer) {
                'single' => app(SaveEmployeeApprovalChainAction::class)->execute($target, [
                    ['step_type' => 'kepala_bagian', 'role_label' => 'Atasan Langsung', 'approver_employee_id' => $fixture['kepala_bagian_lama']->id, 'is_final' => false],
                    ['step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $newPybmc->id, 'is_final' => true],
                ], $actor, null),
                'global' => app(ApplyGlobalPybmcAction::class)->execute($newPybmc, $actor, 'Perubahan global sebelum batch.'),
                'assignment', 'scope' => app(AssignSupervisorAction::class)->execute($target, $fixture['kepala_bagian_baru']->id, today()->toDateString()),
                'lifecycle' => $target->update(['status_aktif' => 'Non-Aktif']),
                'revoke' => Role::query()->where('name', 'super_admin')->sole()->permissions()->detach(Permission::query()->where('name', 'cuti.configure')->sole()->id),
            };
            $snapshotAfterFirst = $this->rawBatchConfiguration();
        });
        $this->assertFalse($outcome['ok']);
        $this->assertSame($status, $outcome['status']);
        if ($status === 422) {
            $this->assertArrayHasKey('preview_token', $outcome['errors']);
        }
        $this->assertSame($snapshotAfterFirst, $this->rawBatchConfiguration());
        $this->assertSame($fixture['snapshot'], $this->rawStepRows($fixture['leave']));
    }

    public static function batchCompetingWriters(): array
    {
        return [['single', 422], ['global', 422], ['assignment', 422], ['lifecycle', 422], ['revoke', 403], ['scope', 404]];
    }

    public function test_batch_common_approver_nonaktif_saat_union_lock_menghasilkan_validasi_tanpa_write(): void
    {
        $fixture = $this->buatFixtureChainDenganSnapshot();
        $pybmc = Employee::factory()->create(['id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);
        $draft = ['employee_ids' => [$fixture['employee']->id], 'mode' => 'replace', 'verifiers' => [], 'pybmc_mode' => 'custom', 'pybmc_employee_id' => $pybmc->id, 'reason' => null];
        $paths = $this->racePaths('batch-common-inactive');
        $process = $this->batchWorker($paths, $fixture['actor'], $draft);
        $before = $this->rawBatchConfiguration();
        DB::beginTransaction();
        try {
            // Lifecycle memegang satu employee row tanpa configuration lock; batch harus menunggu union.
            Employee::query()->whereKey($pybmc->id)->lockForUpdate()->sole();
            $process->start();
            $this->assertTrue($this->tungguFile($paths['pid'], 30_000));
            $pid = (int) File::get($paths['pid']);
            $deadline = microtime(true) + 30;
            do {
                // pg_locks dibaca live; snapshot statistik aktivitas dapat tetap lama sepanjang transaksi pengamat.
                $waiting = DB::table('pg_locks')->where('pid', $pid)
                    ->where('locktype', 'transactionid')->where('granted', false)->exists();
                if ($waiting) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Batch harus terbukti menunggu union row lock sebelum lifecycle berubah.');
            $pybmc->update(['status_aktif' => 'Non-Aktif']);
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outcome = json_decode(File::get($paths['result']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($outcome['ok']);
            $this->assertSame(422, $outcome['status']);
            $this->assertNotEmpty($outcome['errors']);
            $this->assertSame($before, $this->rawBatchConfiguration());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }

    public function test_global_menolak_permission_yang_dicabut_saat_menunggu_union_row_lock(): void
    {
        $fixture = $this->buatFixtureChainDenganSnapshot();
        $pybmc = Employee::factory()->create();
        $paths = $this->racePaths('global-union-revoke');
        $process = $this->worker([
            'action' => 'global', ...$paths,
            'actor_id' => $fixture['actor']->id,
            'approver_id' => $pybmc->id,
        ]);
        $before = $this->rawBatchConfiguration();
        $globalBefore = DB::table('leave_pybmc_global_config')->orderBy('id')->get()->toJson();

        DB::beginTransaction();
        try {
            // Cabut izin setelah worker lolos configuration lock tetapi masih menunggu row approver.
            Employee::query()->whereKey($pybmc->id)->lockForUpdate()->sole();
            $process->start();
            $this->assertTrue($this->tungguFile($paths['pid'], 30_000));
            $pid = (int) File::get($paths['pid']);
            $deadline = microtime(true) + 30;
            do {
                $waiting = DB::table('pg_locks')->where('pid', $pid)
                    ->where('locktype', 'transactionid')->where('granted', false)->exists();
                if ($waiting) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Writer global harus menunggu row approver sebelum permission dicabut.');
            Role::query()->where('name', 'super_admin')->sole()->permissions()
                ->detach(Permission::query()->where('name', 'cuti.configure')->sole()->id);
            DB::commit();
            $process->wait();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outcome = json_decode(File::get($paths['result']), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($outcome['ok']);
            $this->assertSame(403, $outcome['status']);
            $this->assertSame($before, $this->rawBatchConfiguration());
            $this->assertSame($globalBefore, DB::table('leave_pybmc_global_config')->orderBy('id')->get()->toJson());
            $this->assertSame($fixture['snapshot'], $this->rawStepRows($fixture['leave']));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }

    #[DataProvider('actorRoleChanges')]
    public function test_writer_menolak_aktor_yang_rolenya_berubah_saat_menunggu_lock(string $writer, bool $permissionTetapAda, int $expectedStatus, bool $lockPegawai): void
    {
        $fixture = $this->buatFixtureChainDenganSnapshot();
        $actor = $fixture['actor'];
        $actorEmployee = Employee::factory()->create();
        $actor->update([
            'role' => 'admin_kepegawaian',
            'employee_id' => $actorEmployee->id,
            'keycloak_id' => (string) Str::uuid(),
        ]);
        $mappingAdmin = User::factory()->superAdmin()->create();
        $mappingRequest = Request::create('/mapping', 'POST');
        $mappingRequest->setUserResolver(fn (): User => $mappingAdmin);
        if ($permissionTetapAda) {
            Role::query()->where('name', 'pegawai')->sole()->permissions()
                ->syncWithoutDetaching([Permission::query()->where('name', 'cuti.configure')->sole()->id]);
        }
        $pybmc = Employee::factory()->create(['id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']);
        $draft = [
            'employee_ids' => [$fixture['employee']->id],
            'mode' => 'replace',
            'verifiers' => [],
            'pybmc_mode' => 'custom',
            'pybmc_employee_id' => $pybmc->id,
            'reason' => null,
        ];
        $paths = $this->racePaths('actor-role');
        $process = match ($writer) {
            'batch' => $this->batchWorker($paths, $actor, $draft),
            'individual' => $this->saveWorker($paths, $actor, $fixture['employee'], $fixture['kepala_bagian_lama'], $pybmc),
            'global' => $this->worker(['action' => 'global', ...$paths, 'actor_id' => $actor->id, 'approver_id' => $pybmc->id]),
        };
        $afterMapping = [];
        $globalBefore = DB::table('leave_pybmc_global_config')->orderBy('id')->get()->toJson();

        $outcome = $this->jalankanDenganPerubahanSaatWorkerMenunggu($process, $paths, function () use ($actor, $actorEmployee, $mappingRequest, &$afterMapping): void {
            // Jalur mapping resmi mengubah role setelah worker terbukti menunggu lock penerapan.
            $updated = app(UpdateUserMappingAction::class)->execute([
                'employee_id' => $actorEmployee->id,
                'keycloak_id' => $actor->keycloak_id,
                'role' => 'pegawai',
            ], $mappingRequest);
            $this->assertTrue($updated->is($actor));
            $this->assertSame('pegawai', $updated->role);
            $afterMapping = $this->rawBatchConfiguration();
        }, $lockPegawai ? $pybmc->id : null);

        $this->assertSame('pegawai', $actor->fresh()->role);
        $this->assertSame($permissionTetapAda, $actor->fresh()->hasPermission('cuti.configure'));
        $this->assertFalse($outcome['ok'], 'Writer '.$writer.' tetap lolos setelah demosi role: '.json_encode($outcome, JSON_THROW_ON_ERROR));
        $this->assertSame($expectedStatus, $outcome['status']);
        $this->assertSame($afterMapping, $this->rawBatchConfiguration());
        $this->assertSame($globalBefore, DB::table('leave_pybmc_global_config')->orderBy('id')->get()->toJson());
        $this->assertSame($fixture['snapshot'], $this->rawStepRows($fixture['leave']));
    }

    public static function actorRoleChanges(): array
    {
        return [
            'batch permission hilang saat lock konfigurasi' => ['batch', false, 403, false],
            'batch scope menyempit saat lock konfigurasi' => ['batch', true, 404, false],
            'batch permission hilang saat lock pegawai' => ['batch', false, 403, true],
            'batch scope menyempit saat lock pegawai' => ['batch', true, 404, true],
            'individual permission hilang saat lock konfigurasi' => ['individual', false, 403, false],
            'individual scope menyempit saat lock konfigurasi' => ['individual', true, 404, false],
            'individual permission hilang saat lock pegawai' => ['individual', false, 403, true],
            'individual scope menyempit saat lock pegawai' => ['individual', true, 404, true],
            'global permission hilang saat lock konfigurasi' => ['global', false, 403, false],
            'global scope menyempit saat lock konfigurasi' => ['global', true, 403, false],
            'global permission hilang saat lock pegawai' => ['global', false, 403, true],
            'global scope menyempit saat lock pegawai' => ['global', true, 403, true],
        ];
    }

    private function batchWorker(array $paths, User $actor, array $draft): Process
    {
        $preview = app(PreviewEmployeeApprovalChainsAction::class)->execute($actor, $draft);

        return $this->worker(['action' => 'batch', ...$paths, 'actor_id' => $actor->id, 'draft' => $draft, 'preview_token' => $preview['data']['preview_token']]);
    }

    /** Mutation batch yang gagal tidak boleh meninggalkan successor, langkah, atau audit parsial. */
    private function rawBatchConfiguration(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), ['leave_approval_chains', 'leave_approval_chain_steps', 'audit_logs']);
    }

    /**
     * @param  array{booted:string, ready:string, stage:string, result:string, pid:string}  $paths
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
     * Mengubah state setelah worker melewati guard awal dan sedang menunggu lock,
     * lalu memastikan writer membaca state terbaru yang sudah committed.
     *
     * @param  array{booted:string, ready:string, stage:string, result:string, pid:string}  $paths
     * @return array<string, mixed>
     */
    private function jalankanDenganPerubahanSaatWorkerMenunggu(
        Process $process,
        array $paths,
        callable $mutation,
        ?string $employeeLockId = null,
    ): array {
        DB::beginTransaction();

        try {
            if ($employeeLockId === null) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::GLOBAL_LOCK_KEY]);
            } else {
                Employee::query()->whereKey($employeeLockId)->lockForUpdate()->sole();
            }
            $process->start();
            $this->assertTrue($this->tungguFile($paths['booted'], 30_000));
            $this->assertTrue($this->tungguFile($paths['ready'], 30_000));
            $this->assertTrue($this->tungguFile($paths['pid'], 30_000));
            $this->assertTrue(
                $this->tungguLockWorker((int) File::get($paths['pid']), 30_000, $employeeLockId === null ? 'advisory' : 'transactionid'),
                'Worker wajib terbukti menunggu lock sebelum state diubah.',
            );
            $this->assertFalse(File::exists($paths['result']));
            $mutation();
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
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertTrue($this->tungguFile($paths['result'], 1_000));

        return json_decode(File::get($paths['result']), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array{booted:string, ready:string, stage:string, result:string, pid:string} $paths */
    private function saveWorker(array $paths, User $actor, Employee $target, Employee $supervisor, Employee $pybmc): Process
    {
        return $this->worker([
            'action' => 'save',
            ...$paths,
            'actor_id' => $actor->id,
            'employee_id' => $target->id,
            'kepala_bagian_id' => $supervisor->id,
            'pybmc_id' => $pybmc->id,
        ]);
    }

    private function buatChainExisting(Employee $target, Employee $supervisor, Employee $pybmc, User $actor): LeaveApprovalChain
    {
        $chain = LeaveApprovalChain::create([
            'employee_id' => $target->id,
            'name' => 'Chain sebelum recheck authority',
            'is_active' => true,
            'effective_from' => today(),
            'created_by' => $actor->id,
        ]);
        $chain->steps()->createMany([
            ['step_order' => 1, 'step_type' => 'kepala_bagian', 'role_label' => 'Atasan Langsung', 'approver_employee_id' => $supervisor->id, 'is_final' => false],
            ['step_order' => 2, 'step_type' => 'pybmc', 'role_label' => 'PYBMC', 'approver_employee_id' => $pybmc->id, 'is_final' => true],
        ]);

        return $chain;
    }

    private function tungguLockWorker(int $pid, int $timeoutMilliseconds, string $lockType): bool
    {
        $deadline = microtime(true) + ($timeoutMilliseconds / 1000);

        do {
            $waiting = DB::table('pg_locks')
                ->where('pid', $pid)
                ->where('locktype', $lockType)
                ->where('granted', false)
                ->exists();

            if ($waiting) {
                return true;
            }

            usleep(10_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * Menahan transaksi writer pertama sampai worker kedua siap memasuki Action, sehingga urutan
     * serialisasi dibuktikan dengan barrier file dan bukan asumsi waktu mulai proses.
     *
     * @param  callable(): mixed  $firstWriter
     * @param  array{booted:string, ready:string, stage:string, result:string, pid:string}  $paths
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
     * @return array{booted:string, ready:string, stage:string, result:string, pid:string}
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
            'pid' => $directory.'/pid-'.$suffix,
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
        // Data runner disposable dibersihkan agar teardown tidak membuang revisi melalui guard rollback.
        DB::table('leave_pybmc_global_config')->delete();
    }
}
