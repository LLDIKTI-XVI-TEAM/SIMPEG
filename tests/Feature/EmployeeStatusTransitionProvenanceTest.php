<?php

namespace Tests\Feature;

use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Actions\Ews\UpdateEwsAlertFollowupAction;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStatusTransition;
use App\Models\EwsAlert;
use App\Models\Permission;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\User;
use App\Services\Employees\EmployeeStatusTransitionService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/** Regresi provenance immutable dan otorisasi beku transisi status terjadwal. */
class EmployeeStatusTransitionProvenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_role_simulasi_dibekukan_saat_schedule_lalu_revert_tidak_mengubah_audit_due(): void
    {
        $actor = User::factory()->superAdmin()->create([
            'name' => 'Aktor Jadwal Simulasi',
            'temporary_role' => 'admin_kepegawaian',
            'temporary_role_started_at' => now(),
        ]);
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();
        $request = $this->actorRequest($actor, '/pegawai/status', [
            'tanggal' => $tanggal,
            'keterangan' => 'Penugasan belajar terjadwal.',
        ]);

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Penugasan belajar terjadwal.',
        ], $request);

        $actor->forceFill([
            'temporary_role' => null,
            'temporary_permission' => null,
            'temporary_role_started_at' => null,
            'temporary_role_switched_by' => null,
        ])->saveOrFail();
        Auth::logout();

        $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));

        $transition = EmployeeStatusTransition::query()->sole();
        $audit = AuditLog::query()->where('auditable_type', 'Employee')->sole();

        $this->assertSame($actor->id, $transition->actor_user_id_snapshot);
        $this->assertSame('Aktor Jadwal Simulasi', $transition->actor_name_snapshot);
        $this->assertSame('super_admin', $transition->actor_original_role);
        $this->assertSame('admin_kepegawaian', $transition->actor_effective_role);
        $this->assertTrue($transition->actor_simulation);
        $this->assertSame('employees.update', $transition->authorization_permission);
        $this->assertSame(EmployeeStatusTransition::KIND_STATUS, $transition->authorization_action);
        $this->assertSame('10.20.30.40', $transition->actor_ip_address);
        $this->assertSame('SIMPEG-Provenance-Test/1.0', $transition->actor_user_agent);
        $this->assertSame($actor->id, $audit->user_id);
        $this->assertSame('Aktor Jadwal Simulasi', $audit->user_name);
        $this->assertSame('super_admin', $audit->new_values['_original_role'] ?? null);
        $this->assertSame('admin_kepegawaian', $audit->new_values['_effective_role'] ?? null);
        $this->assertTrue($audit->new_values['_simulation'] ?? false);
        $this->assertSame('employees.update', $audit->new_values['_authorization_permission'] ?? null);
        $this->assertSame(EmployeeStatusTransition::KIND_STATUS, $audit->new_values['_authorization_action'] ?? null);
        $this->assertSame('10.20.30.40', $audit->ip_address);
        $this->assertSame('SIMPEG-Provenance-Test/1.0', $audit->user_agent);
    }

    public function test_permission_yang_dicabut_setelah_schedule_tidak_membatalkan_deaktivasi_sah(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();
        $request = $this->actorRequest($actor, '/pegawai/deactivate', [
            'tanggal_efektif' => $tanggal,
            'alasan' => 'Pensiun administratif terjadwal.',
        ]);

        Auth::login($actor);
        app(DeactivateEmployeeAction::class)->execute($employee, $request);

        $role = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.deactivate')->firstOrFail();
        $role->permissions()->detach($permission->id);
        Auth::logout();

        $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertFalse($employee->refresh()->isActive());
        $this->assertTrue(EmployeeStatusTransition::query()->sole()->is_applied);
    }

    public function test_due_generic_active_ke_active_gagal_jika_source_berubah_menjadi_nonaktif(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $nonaktif = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Awalnya perubahan antar status aktif.',
        ], $this->actorRequest($actor, '/pegawai/status', ['tanggal' => $tanggal]));
        Auth::logout();

        Employee::query()->whereKey($employee->id)->update([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
        ]);

        $this->assertSame('employees.update', EmployeeStatusTransition::query()->sole()->authorization_permission);
        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertFalse(EmployeeStatusTransition::query()->sole()->is_applied);
        $this->assertSame($nonaktif->id, $employee->refresh()->status_pegawai_id);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_due_generic_nonaktif_ke_nonaktif_gagal_jika_source_berubah_menjadi_aktif(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $nonaktif = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $target = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
        ]);
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Awalnya perubahan antar status nonaktif.',
        ], $this->actorRequest($actor, '/pegawai/status', ['tanggal' => $tanggal]));
        Auth::logout();

        Employee::query()->whereKey($employee->id)->update([
            'status_pegawai_id' => $aktif->id,
            'status_aktif' => $aktif->nama,
        ]);

        $this->assertSame('employees.update', EmployeeStatusTransition::query()->sole()->authorization_permission);
        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertFalse(EmployeeStatusTransition::query()->sole()->is_applied);
        $this->assertSame($aktif->id, $employee->refresh()->status_pegawai_id);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_due_generic_active_ke_nonaktif_tetap_berjalan_setelah_permission_live_dicabut(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Generic deactivate terjadwal.',
        ], $this->actorRequest($actor, '/pegawai/status', ['tanggal' => $tanggal]));
        $this->revokeRolePermission('admin_kepegawaian', 'employees.deactivate');
        Auth::logout();

        $this->assertSame('employees.deactivate', EmployeeStatusTransition::query()->sole()->authorization_permission);
        $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertFalse($employee->refresh()->isActive());
    }

    public function test_due_generic_nonaktif_ke_aktif_tetap_berjalan_setelah_permission_live_dicabut(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $nonaktif = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
        $target = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
        ]);
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Generic restore terjadwal.',
        ], $this->actorRequest($actor, '/pegawai/status', ['tanggal' => $tanggal]));
        $this->revokeRolePermission('admin_kepegawaian', 'employees.restore');
        Auth::logout();

        $this->assertSame('employees.restore', EmployeeStatusTransition::query()->sole()->authorization_permission);
        $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertTrue($employee->refresh()->isActive());
    }

    public function test_due_restore_menolak_snapshot_role_efektif_di_luar_allowlist(): void
    {
        $nonaktif = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
        $target = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $employee = Employee::factory()->create([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
        ]);
        $tanggal = now('Asia/Makassar')->toDateString();
        $transitionId = (string) Str::uuid();

        // Meniru row captured dari deployment lama/sumber eksternal. Scheduler wajib
        // memvalidasi ulang invariant snapshot tanpa membaca konfigurasi RBAC live.
        DB::table('employee_status_transitions')->insert([
            'id' => $transitionId,
            'employee_id' => $employee->id,
            'status_pegawai_id' => $target->id,
            'tanggal_efektif' => $tanggal,
            'kind' => EmployeeStatusTransition::KIND_RESTORE,
            'keterangan' => 'Snapshot restore dengan role tidak sah.',
            'actor_user_id_snapshot' => (string) Str::uuid(),
            'actor_name_snapshot' => 'Aktor Pimpinan Lama',
            'actor_original_role' => 'pimpinan',
            'actor_effective_role' => 'pimpinan',
            'authorization_permission' => 'employees.restore',
            'authorization_action' => EmployeeStatusTransition::KIND_RESTORE,
            'actor_simulation' => false,
            'actor_ip_address' => '10.20.30.40',
            'actor_user_agent' => 'SIMPEG-Provenance-Test/1.0',
            'provenance_status' => EmployeeStatusTransition::PROVENANCE_CAPTURED,
            'is_applied' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertFalse(EmployeeStatusTransition::query()->findOrFail($transitionId)->is_applied);
        $this->assertFalse($employee->refresh()->isActive());
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_user_dihapus_setelah_schedule_tetap_mempertahankan_identitas_dan_menerapkan_semua_kind(): void
    {
        $actor = User::factory()->superAdmin()->create(['name' => 'Aktor Akan Dihapus']);
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();
        $targetGeneric = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $nonaktif = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
        $genericEmployee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $deactivateEmployee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $restoreEmployee = Employee::factory()->create([
            'status_pegawai_id' => $nonaktif->id,
            'status_aktif' => $nonaktif->nama,
        ]);

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($genericEmployee, [
            'status_pegawai_id' => $targetGeneric->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Status generik terjadwal.',
        ], $this->actorRequest($actor, '/pegawai/status', [
            'tanggal' => $tanggal,
            'keterangan' => 'Status generik terjadwal.',
        ]));
        app(DeactivateEmployeeAction::class)->execute($deactivateEmployee, $this->actorRequest(
            $actor,
            '/pegawai/deactivate',
            ['tanggal_efektif' => $tanggal, 'alasan' => 'Deaktivasi terjadwal.'],
        ));
        app(RestoreEmployeeAction::class)->execute($restoreEmployee, $this->actorRequest(
            $actor,
            '/pegawai/restore',
            ['tanggal_efektif' => $tanggal, 'alasan' => 'Reaktivasi terjadwal.'],
        ));
        Auth::logout();

        $actorId = $actor->id;
        $actor->delete();
        $sentinel = User::factory()->pegawai()->create();
        Auth::login($sentinel);

        $this->assertNull(EmployeeStatusTransition::query()->firstOrFail()->created_by_user_id);
        $this->assertSame(3, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertSame($sentinel->id, Auth::id());
        $this->assertSame(3, EmployeeStatusTransition::query()->where('is_applied', true)->count());
        $this->assertSame(3, EmployeeStatusHistory::query()->count());
        $this->assertSame(
            [null],
            EmployeeStatusHistory::query()->pluck('changed_by_user_id')->unique()->values()->all(),
        );
        $this->assertSame(3, AuditLog::query()->where('auditable_type', 'Employee')->count());
        $this->assertSame(
            [$actorId],
            EmployeeStatusTransition::query()->get()->pluck('actor_user_id_snapshot')->unique()->values()->all(),
        );
        $this->assertSame(
            [$actorId],
            AuditLog::query()->where('auditable_type', 'Employee')->pluck('user_id')->unique()->values()->all(),
        );
        $this->assertSame(
            ['employees.deactivate', 'employees.restore', 'employees.update'],
            EmployeeStatusTransition::query()->get()->pluck('authorization_permission')->sort()->values()->all(),
        );
        $this->assertSame(
            [EmployeeStatusTransition::KIND_DEACTIVATE, EmployeeStatusTransition::KIND_RESTORE, EmployeeStatusTransition::KIND_STATUS],
            EmployeeStatusTransition::query()->get()->pluck('authorization_action')->sort()->values()->all(),
        );
    }

    public function test_schedule_tanpa_provenance_aktor_ditolak_fail_closed(): void
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();

        $this->expectException(ValidationException::class);

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $target,
            now('Asia/Makassar')->addDay()->toDateString(),
            EmployeeStatusTransition::KIND_STATUS,
            'Caller tanpa provenance.',
        );
    }

    public function test_bypass_api_local_tanpa_user_menyimpan_provenance_sistem_untuk_jadwal(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        config(['services.simpeg.disable_employee_api_auth' => true]);
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();
        $request = Request::create('/api/v1/pegawai/status', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        $request->headers->remove('User-Agent');

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $target,
            $tanggal,
            EmployeeStatusTransition::KIND_STATUS,
            'Jadwal melalui bypass API lokal.',
            actorContext: $request,
        );

        $transition = EmployeeStatusTransition::query()->sole();
        $this->assertSame('SIMPEG Local API Bypass', $transition->actor_name_snapshot);
        $this->assertSame('local_api_bypass', $transition->actor_original_role);
        $this->assertSame('local_api_bypass', $transition->actor_effective_role);
        $this->assertSame('127.0.0.1', $transition->actor_ip_address);
        $this->assertSame('tidak-dikirim', $transition->actor_user_agent);
        $this->assertNull($transition->created_by_user_id);
        $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertSame($target->id, $employee->refresh()->status_pegawai_id);
    }

    public function test_bypass_api_local_tetap_mencatat_user_terautentikasi(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        config(['services.simpeg.disable_employee_api_auth' => true]);
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();
        $request = $this->actorRequest($actor, '/api/v1/pegawai/status', []);

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $target,
            $tanggal,
            EmployeeStatusTransition::KIND_STATUS,
            'Jadwal lokal oleh user terautentikasi.',
            actorContext: $request,
        );

        $transition = EmployeeStatusTransition::query()->sole();
        $this->assertSame($actor->id, $transition->actor_user_id_snapshot);
        $this->assertSame($actor->id, $transition->created_by_user_id);
        $this->assertSame('admin_kepegawaian', $transition->actor_original_role);
        $this->assertSame('admin_kepegawaian', $transition->actor_effective_role);
    }

    public function test_request_terautentikasi_tanpa_user_agent_tetap_dapat_menjadwalkan_status(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();
        $request = Request::create('/pegawai/status', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.20.30.40',
        ]);
        $request->headers->remove('User-Agent');
        $request->setUserResolver(static fn (): User => $actor);

        app(EmployeeStatusTransitionService::class)->schedule(
            $employee,
            $target,
            $tanggal,
            EmployeeStatusTransition::KIND_STATUS,
            'Jadwal tanpa header User-Agent.',
            actorContext: $request,
        );

        $transition = EmployeeStatusTransition::query()->sole();
        $this->assertSame($actor->id, $transition->actor_user_id_snapshot);
        $this->assertSame('tidak-dikirim', $transition->actor_user_agent);
        $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertSame($target->id, $employee->refresh()->status_pegawai_id);
    }

    public function test_scheduler_tidak_memutasi_global_auth_saat_menerapkan_provenance_aktor_lain(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $sentinel = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Uji isolasi Auth.',
        ], $this->actorRequest($actor, '/pegawai/status', [
            'tanggal' => $tanggal,
            'keterangan' => 'Uji isolasi Auth.',
        ]));

        Auth::login($sentinel);

        $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertTrue(Auth::check());
        $this->assertSame($sentinel->id, Auth::id());
    }

    public function test_kegagalan_audit_due_mempertahankan_jadwal_pending_tanpa_side_effect(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Audit wajib berhasil.',
        ], $this->actorRequest($actor, '/pegawai/status', [
            'tanggal' => $tanggal,
            'keterangan' => 'Audit wajib berhasil.',
        ]));
        Auth::logout();

        AuditLog::creating(static function (): never {
            throw new RuntimeException('Paksa gagal audit provenance.');
        });

        $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue($tanggal));
        $this->assertFalse(EmployeeStatusTransition::query()->sole()->is_applied);
        $this->assertSame('Aktif', $employee->refresh()->status_aktif);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_migration_menandai_semua_pending_legacy_missing_tanpa_mengarang_provenance_live(): void
    {
        $migrationPath = database_path('migrations/2026_08_28_000000_add_actor_provenance_to_employee_status_transitions.php');
        $this->assertFileExists($migrationPath);

        $migration = require $migrationPath;
        $migration->down();

        $actor = User::factory()->adminKepegawaian()->create([
            'name' => 'Aktor Legacy',
        ]);
        DB::table('sessions')->insert([
            'id' => 'legacy-provenance-session',
            'user_id' => $actor->id,
            'ip_address' => '10.8.0.9',
            'user_agent' => 'Legacy-SIMPEG/1.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
        $status = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $pendingBackfilled = $this->legacyTransition($status, $actor->id, false);
        $pendingMissing = $this->legacyTransition($status, null, false);
        $appliedHistorical = $this->legacyTransition($status, null, true);

        try {
            $migration->up();

            $withLiveActor = EmployeeStatusTransition::query()->findOrFail($pendingBackfilled);
            $missing = EmployeeStatusTransition::query()->findOrFail($pendingMissing);
            $historical = EmployeeStatusTransition::query()->findOrFail($appliedHistorical);

            $this->assertSame(EmployeeStatusTransition::PROVENANCE_MISSING, $withLiveActor->provenance_status);
            $this->assertSame($actor->id, $withLiveActor->created_by_user_id);
            $this->assertNull($withLiveActor->actor_user_id_snapshot);
            $this->assertNull($withLiveActor->actor_name_snapshot);
            $this->assertNull($withLiveActor->actor_original_role);
            $this->assertNull($withLiveActor->actor_effective_role);
            $this->assertNull($withLiveActor->authorization_permission);
            $this->assertNull($withLiveActor->authorization_action);
            $this->assertNull($withLiveActor->actor_simulation);
            $this->assertNull($withLiveActor->actor_ip_address);
            $this->assertNull($withLiveActor->actor_user_agent);
            $this->assertSame(EmployeeStatusTransition::PROVENANCE_MISSING, $missing->provenance_status);
            $this->assertSame(EmployeeStatusTransition::PROVENANCE_LEGACY_HISTORICAL, $historical->provenance_status);

            $this->assertSame(0, app(EmployeeStatusTransitionService::class)->applyDue(now('Asia/Makassar')->addDays(10)->toDateString()));
            $this->assertFalse($withLiveActor->refresh()->is_applied);
            $this->assertFalse($missing->refresh()->is_applied);
        } finally {
            DB::table('employee_status_transitions')->whereIn('id', [
                $pendingBackfilled,
                $pendingMissing,
                $appliedHistorical,
            ])->delete();
        }
    }

    public function test_mass_assignment_umum_tidak_dapat_mengubah_provenance(): void
    {
        $transition = $this->scheduledGenericTransition();
        $originalName = $transition->actor_name_snapshot;

        $transition->update([
            'actor_name_snapshot' => 'Nama hasil manipulasi',
            'provenance_status' => EmployeeStatusTransition::PROVENANCE_MISSING,
        ]);

        $this->assertSame($originalName, $transition->refresh()->actor_name_snapshot);
        $this->assertSame(EmployeeStatusTransition::PROVENANCE_CAPTURED, $transition->provenance_status);
    }

    public function test_source_ews_retirement_dibekukan_dan_tidak_dapat_dialihkan_setelah_schedule(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $tanggal = now('Asia/Makassar')->addDays(30)->toDateString();
        $source = EwsAlert::query()->create([
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'target_date' => $tanggal,
            'interval_days' => 90,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $sibling = EwsAlert::query()->create([
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'target_date' => $tanggal,
            'interval_days' => 180,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $request = $this->actorRequest($actor, '/ews/followup', [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Pensiun terjadwal.',
            'no_sk' => 'SK-PENSIUN-PROVENANCE',
            'tanggal_sk' => $tanggal,
        ]);
        $request->files->set(
            'file_sk',
            UploadedFile::fake()->createWithContent('sk-pensiun.pdf', 'SK pensiun provenance'),
        );

        app(UpdateEwsAlertFollowupAction::class)->execute(
            $source,
            EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'Pensiun terjadwal.',
            $request,
        );

        $transition = EmployeeStatusTransition::query()->sole();
        $this->assertSame($source->id, $transition->source_ews_alert_id);
        $this->assertSame('employees.deactivate', $transition->authorization_permission);
        $this->assertSame(EmployeeStatusTransition::KIND_DEACTIVATE, $transition->authorization_action);

        try {
            DB::transaction(function () use ($transition, $sibling): void {
                DB::table('employee_status_transitions')
                    ->where('id', $transition->id)
                    ->update(['source_ews_alert_id' => $sibling->id]);
            });
            $this->fail('Source EWS wajib immutable setelah jadwal dibuat.');
        } catch (QueryException) {
            $this->assertSame($source->id, $transition->refresh()->source_ews_alert_id);
        }
    }

    public function test_migration_source_ews_menolak_rollback_sebelum_mutasi_bila_data_retirement_ada(): void
    {
        Storage::fake(Document::STORAGE_DISK);
        $actor = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $tanggal = now('Asia/Makassar')->addDays(30)->toDateString();
        $source = EwsAlert::query()->create([
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'target_date' => $tanggal,
            'interval_days' => 90,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $request = $this->actorRequest($actor, '/ews/followup', [
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_note' => 'Pensiun untuk uji rollback.',
            'no_sk' => 'SK-PENSIUN-ROLLBACK',
            'tanggal_sk' => $tanggal,
        ]);
        $request->files->set(
            'file_sk',
            UploadedFile::fake()->createWithContent('sk-pensiun.pdf', 'SK pensiun rollback'),
        );
        app(UpdateEwsAlertFollowupAction::class)->execute(
            $source,
            EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'Pensiun untuk uji rollback.',
            $request,
        );

        $transition = EmployeeStatusTransition::query()->sole();
        $migration = require database_path('migrations/2026_08_28_000002_add_ews_source_to_employee_status_transitions.php');
        $exception = null;

        try {
            $migration->down();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        } finally {
            // Production lama menjatuhkan kolom; pulihkan schema agar RED tetap terisolasi.
            if (! Schema::hasColumn('employee_status_transitions', 'source_ews_alert_id')) {
                DB::table('employee_status_transitions')
                    ->where('kind', EmployeeStatusTransition::KIND_EWS_RETIREMENT)
                    ->delete();
                $migration->up();
            }
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertStringContainsString('ews_retirement', $exception->getMessage());
        $this->assertTrue(Schema::hasColumn('employee_status_transitions', 'source_ews_alert_id'));
        $this->assertTrue(Schema::hasColumn('ews_alerts', 'followup_group_id'));
        $this->assertSame($source->id, $transition->refresh()->source_ews_alert_id);

        try {
            DB::transaction(function () use ($transition): void {
                DB::table('employee_status_transitions')
                    ->where('id', $transition->id)
                    ->update(['source_ews_alert_id' => null]);
            });
            $this->fail('Trigger provenance source wajib tetap aktif setelah rollback ditolak.');
        } catch (QueryException) {
            $this->assertSame($source->id, $transition->refresh()->source_ews_alert_id);
        }
    }

    public function test_model_force_fill_ditolak_database_saat_mengubah_provenance(): void
    {
        $transition = $this->scheduledGenericTransition();
        $originalName = $transition->actor_name_snapshot;

        try {
            DB::transaction(function () use ($transition): void {
                $transition->forceFill(['actor_name_snapshot' => 'Nama hasil manipulasi'])->saveOrFail();
            });
            $this->fail('Database wajib menolak perubahan provenance melalui model.');
        } catch (QueryException) {
            $this->assertSame($originalName, $transition->refresh()->actor_name_snapshot);
        }
    }

    public function test_sql_langsung_ditolak_sedangkan_apply_normal_tetap_dapat_memperbarui_marker(): void
    {
        $transition = $this->scheduledGenericTransition();

        try {
            DB::transaction(function () use ($transition): void {
                DB::table('employee_status_transitions')
                    ->where('id', $transition->id)
                    ->update(['authorization_permission' => 'employees.restore']);
            });
            $this->fail('Database wajib menolak perubahan provenance melalui SQL langsung.');
        } catch (QueryException) {
            $this->assertSame('employees.update', $transition->refresh()->authorization_permission);
        }

        $this->assertSame(1, app(EmployeeStatusTransitionService::class)->applyDue(
            $transition->tanggal_efektif->toDateString(),
        ));
        $this->assertTrue($transition->refresh()->is_applied);
    }

    /** @param  array<string, mixed>  $data */
    private function actorRequest(User $actor, string $uri, array $data): Request
    {
        $request = Request::create($uri, 'POST', $data, [], [], [
            'REMOTE_ADDR' => '10.20.30.40',
            'HTTP_USER_AGENT' => 'SIMPEG-Provenance-Test/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $actor);

        return $request;
    }

    private function revokeRolePermission(string $roleName, string $permissionName): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $permission = Permission::query()->where('name', $permissionName)->firstOrFail();
        $role->permissions()->detach($permission->id);
    }

    private function scheduledGenericTransition(): EmployeeStatusTransition
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $tanggal = now('Asia/Makassar')->addDay()->toDateString();

        Auth::login($actor);
        app(ChangeEmployeeStatusAction::class)->execute($employee, [
            'status_pegawai_id' => $target->id,
            'tanggal' => $tanggal,
            'keterangan' => 'Jadwal untuk uji immutable.',
        ], $this->actorRequest($actor, '/pegawai/status', ['tanggal' => $tanggal]));
        Auth::logout();

        return EmployeeStatusTransition::query()->sole();
    }

    private function legacyTransition(RefStatusPegawai $status, ?string $actorId, bool $applied): string
    {
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);
        $id = (string) str()->uuid();

        DB::table('employee_status_transitions')->insert([
            'id' => $id,
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'tanggal_efektif' => now('Asia/Makassar')->subDay()->toDateString(),
            'kind' => EmployeeStatusTransition::KIND_STATUS,
            'keterangan' => 'Jadwal legacy.',
            'created_by_user_id' => $actorId,
            'is_applied' => $applied,
            'applied_at' => $applied ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
