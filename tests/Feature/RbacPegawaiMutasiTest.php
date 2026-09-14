<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacPegawaiMutasiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ReferenceSeeder::class);
    }

    public function test_rbac_create_requires_permission(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin)->get(route('rbac.pegawai.create'))->assertOk();

        $pimpinan = User::factory()->pimpinan()->create();
        $this->actingAs($pimpinan)->get(route('rbac.pegawai.create'))->assertForbidden();
        // Route pimpinan.pegawai.create tidak ada: surface pimpinan read-only
        // by design, sehingga tidak ada halaman create pimpinan untuk diuji.
    }

    public function test_rbac_edit_delete_restore_with_scope(): void
    {
        $employee = Employee::factory()->create();
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)->get(route('rbac.pegawai.edit', $employee))->assertOk();

        // Grant update to pimpinan and test (surface edit pimpinan tidak ada;
        // grant dibuktikan melalui surface rbac).
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->syncWithoutDetaching([Permission::where('name', 'employees.update')->firstOrFail()->id]);
        $pimpinan = User::factory()->pimpinan()->create();
        $pimpinan->refresh();
        $this->actingAs($pimpinan)->get(route('rbac.pegawai.edit', $employee))->assertOk();
    }

    public function test_rbac_delete_requires_permission_and_scope(): void
    {
        $employee = Employee::factory()->create();
        $admin = User::factory()->adminKepegawaian()->create();

        // Admin has deactivate
        $this->actingAs($admin)->post(route('rbac.pegawai.destroy', $employee))->assertRedirect();

        // Pegawai without permission cannot
        $pegawaiUser = User::factory()->pegawai()->create();
        $this->actingAs($pegawaiUser)->post(route('rbac.pegawai.destroy', $employee))->assertForbidden();

        // Pegawai with permission but not scope cannot delete other
        Role::where('name', 'pegawai')->firstOrFail()->permissions()->syncWithoutDetaching([
            Permission::where('name', 'employees.deactivate')->firstOrFail()->id,
            Permission::where('name', 'employees.read')->firstOrFail()->id,
        ]);
        $self = Employee::factory()->create();
        $other = Employee::factory()->create();
        $pegawaiSelf = User::factory()->pegawai()->create(['employee_id' => $self->id]);
        $this->actingAs($pegawaiSelf)->post(route('rbac.pegawai.destroy', $self))->assertRedirect();
        $this->actingAs($pegawaiSelf)->post(route('rbac.pegawai.destroy', $other))->assertForbidden();
    }

    public function test_employee_family_create_and_update_permissions_are_strictly_separated(): void
    {
        $employee = Employee::factory()->create();
        $family = EmployeeFamily::create([
            'employee_id' => $employee->id,
            'nama_anggota' => 'Keluarga Awal',
            'hubungan' => 'Istri',
            'nik' => '7171010101010001',
            'tempat_lahir' => 'Manado',
            'tanggal_lahir' => '1990-01-01',
            'jenis_kelamin' => 'P',
            'status_tunjangan' => true,
            'pekerjaan' => 'Guru',
        ]);

        $payload = [
            'nama_anggota' => 'Keluarga Baru',
            'hubungan' => 'Anak',
            'nik' => '7171010101010002',
            'tempat_lahir' => 'Manado',
            'tanggal_lahir' => '2015-05-05',
            'jenis_kelamin' => 'L',
            'status_tunjangan' => true,
            'pekerjaan' => 'Pelajar',
        ];

        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $createPerm = Permission::where('name', 'employee_families.create')->firstOrFail();
        $updatePerm = Permission::where('name', 'employee_families.update')->firstOrFail();

        // 1. Role with ONLY employee_families.update can PUT family, cannot POST family
        $role->permissions()->detach($createPerm->id);
        $userWithUpdateOnly = User::factory()->adminKepegawaian()->create();

        $this->actingAs($userWithUpdateOnly)
            ->putJson("/api/v1/pegawai/{$employee->id}/keluarga/{$family->id}", $payload)
            ->assertOk()
            ->assertJsonPath('family.nama_anggota', 'Keluarga Baru');

        $this->actingAs($userWithUpdateOnly)
            ->postJson("/api/v1/pegawai/{$employee->id}/keluarga", $payload)
            ->assertForbidden();

        // 2. Role with ONLY employee_families.create can POST family, cannot PUT family
        $role->permissions()->detach($updatePerm->id);
        $role->permissions()->attach($createPerm->id);
        $userWithCreateOnly = User::factory()->adminKepegawaian()->create();

        $this->actingAs($userWithCreateOnly)
            ->postJson("/api/v1/pegawai/{$employee->id}/keluarga", $payload)
            ->assertCreated();

        $this->actingAs($userWithCreateOnly)
            ->putJson("/api/v1/pegawai/{$employee->id}/keluarga/{$family->id}", $payload)
            ->assertForbidden();
    }

    public function test_delegated_role_with_employees_create_can_access_collection_post(): void
    {
        $kbRole = Role::where('name', 'kepala_bagian')->firstOrFail();
        $createPerm = Permission::where('name', 'employees.create')->firstOrFail();
        $kbRole->permissions()->syncWithoutDetaching([$createPerm->id]);

        $kepalaBagian = User::factory()->kepalaBagian()->create();

        // check-identity passes without 403 from employee.scope
        $this->actingAs($kepalaBagian)
            ->postJson('/api/v1/pegawai/check-identity', [
                'type' => 'nip',
                'value' => '199001012020011001',
            ])
            ->assertOk()
            ->assertJson(['available' => true]);

        // POST /api/v1/pegawai passes employee.scope and reaches StoreEmployeeRequest validation boundary (422 instead of 403)
        $this->actingAs($kepalaBagian)
            ->postJson('/api/v1/pegawai', [])
            ->assertStatus(422);

        // Without employees.create, delegated user is forbidden (403)
        $kbRole->permissions()->detach($createPerm->id);
        $this->actingAs($kepalaBagian)
            ->postJson('/api/v1/pegawai/check-identity', [
                'type' => 'nip',
                'value' => '199001012020011001',
            ])
            ->assertForbidden();

        $this->actingAs($kepalaBagian)
            ->postJson('/api/v1/pegawai', [])
            ->assertForbidden();
    }
}
