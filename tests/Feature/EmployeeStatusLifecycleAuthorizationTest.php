<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\User;
use App\Services\Employees\EmployeeStatusActorContext;
use App\Services\Employees\EmployeeStatusLifecycleService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmployeeStatusLifecycleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_mutate_fails_closed_when_direct_caller_has_no_actor(): void
    {
        $employee = $this->activeEmployee();
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();

        try {
            app(EmployeeStatusLifecycleService::class)->mutate(
                $employee,
                $target,
                '2026-08-28',
                'Perubahan status tanpa aktor.',
                Request::create('/internal/status', 'POST'),
            );
            $this->fail('Mutasi lifecycle tanpa aktor wajib ditolak fail-closed.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Aktor perubahan status tidak dapat diverifikasi.',
                $exception->errors()['actor'][0],
            );
        }

        $this->assertLifecycleUntouched($employee);
    }

    public function test_mutate_tanpa_actor_diizinkan_hanya_untuk_bypass_api_local(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'local');
        config(['services.simpeg.disable_employee_api_auth' => true]);
        $employee = $this->activeEmployee();
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $request = Request::create('/api/v1/pegawai/status', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $result = app(EmployeeStatusLifecycleService::class)->mutate(
            $employee,
            $target,
            '2026-08-28',
            'Perubahan status melalui bypass API lokal.',
            $request,
        );

        $this->assertTrue($result->changed);
        $this->assertSame($target->id, $employee->refresh()->status_pegawai_id);
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $target->id,
            'changed_by_user_id' => null,
        ]);
        $audit = AuditLog::query()->where('auditable_type', 'Employee')->where('auditable_id', $employee->id)->sole();
        $this->assertSame('00000000-0000-0000-0000-000000000000', $audit->user_id);
        $this->assertSame('SIMPEG Local API Bypass', $audit->user_name);
        $this->assertSame('local_api_bypass', $audit->new_values['_original_role'] ?? null);
        $this->assertSame('local_api_bypass', $audit->new_values['_effective_role'] ?? null);
    }

    public function test_mutate_fails_closed_when_actor_lacks_employees_update(): void
    {
        $employee = $this->activeEmployee();
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $request = $this->requestFor(User::factory()->pegawai()->create());

        try {
            app(EmployeeStatusLifecycleService::class)->mutate(
                $employee,
                $target,
                '2026-08-28',
                'Perubahan status tanpa permission update.',
                $request,
            );
            $this->fail('Mutasi status dalam kelompok aktif wajib memerlukan employees.update.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Mengubah status pegawai memerlukan permission employees.update.',
                $exception->errors()['status_pegawai_id'][0],
            );
        }

        $this->assertLifecycleUntouched($employee);
    }

    public function test_mutate_fails_closed_when_actor_lacks_employees_deactivate(): void
    {
        $employee = $this->activeEmployee();
        $target = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $request = $this->requestFor(User::factory()->pegawai()->create());

        try {
            app(EmployeeStatusLifecycleService::class)->mutate(
                $employee,
                $target,
                '2026-08-28',
                'Penonaktifan tanpa permission.',
                $request,
                intent: EmployeeStatusLifecycleService::INTENT_DEACTIVATE,
            );
            $this->fail('Penonaktifan wajib memerlukan employees.deactivate.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Menonaktifkan pegawai memerlukan permission employees.deactivate.',
                $exception->errors()['status_pegawai_id'][0],
            );
        }

        $this->assertLifecycleUntouched($employee);
    }

    public function test_mutate_fails_closed_when_actor_lacks_employees_restore(): void
    {
        $employee = $this->inactiveEmployee();
        $target = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $role = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.restore')->firstOrFail();
        $role->permissions()->detach($permission->id);
        $request = $this->requestFor(User::factory()->adminKepegawaian()->create());

        try {
            app(EmployeeStatusLifecycleService::class)->mutate(
                $employee,
                $target,
                '2026-08-28',
                'Reaktivasi tanpa permission.',
                $request,
                intent: EmployeeStatusLifecycleService::INTENT_RESTORE,
            );
            $this->fail('Reaktivasi wajib memerlukan employees.restore.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Mengaktifkan kembali pegawai nonaktif memerlukan permission employees.restore.',
                $exception->errors()['status_pegawai_id'][0],
            );
        }

        $this->assertLifecycleUntouched($employee, [
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'NONAKTIF')->value('id'),
            'status_aktif' => 'Non-Aktif',
            'status_keterangan' => 'Status awal nonaktif.',
            'status_note' => 'Catatan awal nonaktif.',
            'status_berkas_path' => 'employees/status/nonaktif.pdf',
            'status_nomor_berkas' => 'SK-NONAKTIF-001',
        ]);
    }

    public function test_mutate_menolak_pimpinan_yang_diberi_permission_restore(): void
    {
        $employee = $this->inactiveEmployee();
        $target = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $actor = User::factory()->create(['role' => 'pimpinan']);
        $this->grantRestorePermissionToRole('pimpinan');
        $this->assertTrue($actor->hasPermission('employees.restore'));

        try {
            app(EmployeeStatusLifecycleService::class)->mutate(
                $employee,
                $target,
                '2026-08-28',
                'Reaktivasi dari role yang tidak diizinkan.',
                $this->requestFor($actor),
                intent: EmployeeStatusLifecycleService::INTENT_RESTORE,
            );
            $this->fail('Reaktivasi wajib menolak role efektif di luar allowlist meski permission diberikan.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Mengaktifkan kembali pegawai hanya dapat dilakukan oleh Super Admin atau Admin Kepegawaian.',
                $exception->errors()['status_pegawai_id'][0],
            );
        }

        $this->assertLifecycleUntouched($employee, [
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'NONAKTIF')->value('id'),
            'status_aktif' => 'Non-Aktif',
            'status_keterangan' => 'Status awal nonaktif.',
            'status_note' => 'Catatan awal nonaktif.',
            'status_berkas_path' => 'employees/status/nonaktif.pdf',
            'status_nomor_berkas' => 'SK-NONAKTIF-001',
        ]);
    }

    public function test_provenance_restore_menolak_pimpinan_yang_diberi_permission_restore(): void
    {
        $actor = User::factory()->create(['role' => 'pimpinan']);
        $this->grantRestorePermissionToRole('pimpinan');

        try {
            EmployeeStatusActorContext::capture(
                $this->requestFor($actor),
                'employees.restore',
                'restore',
            );
            $this->fail('Snapshot jadwal restore tidak boleh dibentuk dari role efektif di luar allowlist.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Role efektif aktor tidak diizinkan untuk mengaktifkan kembali pegawai.',
                $exception->errors()['actor'][0],
            );
        }
    }

    public function test_mutate_accepts_frozen_context_for_temporary_effective_role(): void
    {
        $employee = $this->activeEmployee();
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $actor = User::factory()->superAdmin()->create(['temporary_role' => 'admin_kepegawaian']);
        $request = $this->requestFor($actor);
        $context = EmployeeStatusActorContext::capture(
            $request,
            'employees.update',
            'status',
        );

        $result = app(EmployeeStatusLifecycleService::class)->mutate(
            $employee,
            $target,
            '2026-08-28',
            'Tugas belajar melalui role efektif sementara.',
            $request,
            actorContext: $context,
        );

        $this->assertTrue($result->changed);
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $target->id,
            'changed_by_user_id' => $actor->id,
        ]);
    }

    public function test_mutate_rejects_frozen_context_for_different_permission_and_action(): void
    {
        $employee = $this->activeEmployee();
        $target = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $request = $this->requestFor(User::factory()->superAdmin()->create());
        $context = EmployeeStatusActorContext::capture(
            $request,
            'employees.deactivate',
            'deactivate',
        );

        try {
            app(EmployeeStatusLifecycleService::class)->mutate(
                $employee,
                $target,
                '2026-08-28',
                'Provenance tidak sesuai.',
                $request,
                actorContext: $context,
            );
            $this->fail('Context dengan permission/action berbeda wajib ditolak.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Provenance aktor tidak sesuai dengan jenis transisi status.',
                $exception->errors()['actor'][0],
            );
        }

        $this->assertLifecycleUntouched($employee);
    }

    private function activeEmployee(): Employee
    {
        $active = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();

        return Employee::factory()->create([
            'status_pegawai_id' => $active->id,
            'status_aktif' => $active->nama,
            'status_keterangan' => 'Status awal lifecycle.',
            'status_note' => 'Catatan awal.',
            'status_tanggal' => '2026-08-01',
            'status_berkas_path' => 'employees/status/awal.pdf',
            'status_nomor_berkas' => 'SK-AWAL-001',
        ]);
    }

    private function inactiveEmployee(): Employee
    {
        $inactive = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();

        return Employee::factory()->create([
            'status_pegawai_id' => $inactive->id,
            'status_aktif' => $inactive->nama,
            'status_keterangan' => 'Status awal nonaktif.',
            'status_note' => 'Catatan awal nonaktif.',
            'status_tanggal' => '2026-08-01',
            'status_berkas_path' => 'employees/status/nonaktif.pdf',
            'status_nomor_berkas' => 'SK-NONAKTIF-001',
        ]);
    }

    private function requestFor(User $user): Request
    {
        $request = Request::create('/internal/status', 'POST', [], [], [], [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'SIMPEG-Lifecycle-Authorization-Test/1.0',
        ]);
        $request->setUserResolver(static fn (): User => $user);

        return $request;
    }

    private function grantRestorePermissionToRole(string $roleName): void
    {
        $role = Role::query()->where('name', $roleName)->firstOrFail();
        $permission = Permission::query()->where('name', 'employees.restore')->firstOrFail();

        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    /** @param array<string, string> $expected */
    private function assertLifecycleUntouched(Employee $employee, array $expected = []): void
    {
        $expected += [
            'status_pegawai_id' => RefStatusPegawai::query()->where('kode', 'AKTIF')->value('id'),
            'status_aktif' => 'Aktif',
            'status_keterangan' => 'Status awal lifecycle.',
            'status_note' => 'Catatan awal.',
            'status_berkas_path' => 'employees/status/awal.pdf',
            'status_nomor_berkas' => 'SK-AWAL-001',
        ];
        $employee->refresh();
        $this->assertSame($expected['status_pegawai_id'], $employee->status_pegawai_id);
        $this->assertSame($expected['status_aktif'], $employee->status_aktif);
        $this->assertSame($expected['status_keterangan'], $employee->status_keterangan);
        $this->assertSame($expected['status_note'], $employee->status_note);
        $this->assertSame('2026-08-01', $employee->status_tanggal?->toDateString());
        $this->assertSame($expected['status_berkas_path'], $employee->status_berkas_path);
        $this->assertSame($expected['status_nomor_berkas'], $employee->status_nomor_berkas);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
