<?php

namespace Tests\Feature;

use App\Models\Employee;
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
        $this->actingAs($pimpinan)->get(route('pimpinan.pegawai.create'))->assertForbidden();
    }

    public function test_rbac_edit_delete_restore_with_scope(): void
    {
        $employee = Employee::factory()->create();
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)->get(route('rbac.pegawai.edit', $employee))->assertOk();
        $this->actingAs($admin)->get(route('pimpinan.pegawai.edit', $employee))->assertForbidden(); // pimpinan no update permission

        // Grant update to pimpinan and test
        Role::where('name', 'pimpinan')->firstOrFail()->permissions()->syncWithoutDetaching([Permission::where('name', 'employees.update')->firstOrFail()->id]);
        $pimpinan = User::factory()->pimpinan()->create();
        $pimpinan->refresh();
        $this->actingAs($pimpinan)->get(route('pimpinan.pegawai.edit', $employee))->assertOk();
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
}
