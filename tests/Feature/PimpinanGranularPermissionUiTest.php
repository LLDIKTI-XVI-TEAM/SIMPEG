<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\RefStatusPegawai;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PimpinanGranularPermissionUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    private function pimpinanWithPermissions(array $permissionNames): User
    {
        $role = Role::where('name', 'pimpinan')->firstOrFail();
        // Reset to only the given permissions + employees.read (required for index) for isolation
        $role->permissions()->detach();
        $base = ['employees.read'];
        $all = array_unique(array_merge($base, $permissionNames));
        $ids = Permission::whereIn('name', $all)->pluck('id');
        $role->permissions()->attach($ids);

        return User::factory()->pimpinan()->create();
    }

    private function assertPimpinanIndexSee(User $user, string $needle, bool $shouldSee = true): void
    {
        $response = $this->actingAs($user)->get(route('pimpinan.pegawai.index'));
        $response->assertOk();
        if ($shouldSee) {
            $response->assertSee($needle, false);
        } else {
            $response->assertDontSee($needle, false);
        }
    }

    public function test_pimpinan_hanya_create_melihat_tambah_tidak_edit(): void
    {
        $user = $this->pimpinanWithPermissions(['employees.create']);
        $employee = Employee::factory()->create();

        $this->assertPimpinanIndexSee($user, 'Tambah Manual', true);
        $this->assertPimpinanIndexSee($user, '/rbac/pegawai/create', true);
        $this->assertPimpinanIndexSee($user, '/rbac/pegawai/${p.id}/edit', false);
        $this->assertPimpinanIndexSee($user, 'deletePegawai(p.id', false);
        $this->assertPimpinanIndexSee($user, 'restorePegawai(p.id', false);
        $this->assertPimpinanIndexSee($user, 'SK Wajib', false);

        // Rendered link harus reachable (rbac), bukan 403
        $this->actingAs($user)->get(route('rbac.pegawai.create'))->assertOk();
        $this->actingAs($user)->get(route('rbac.pegawai.edit', $employee))->assertForbidden(); // tidak punya update
        $this->actingAs($user)->get(route('pegawai.create'))->assertForbidden(); // web admin-only 403, UI tidak pakai ini
    }

    public function test_pimpinan_hanya_update_melihat_edit_tidak_tambah(): void
    {
        $user = $this->pimpinanWithPermissions(['employees.update']);
        $employee = Employee::factory()->create();

        $this->assertPimpinanIndexSee($user, '/rbac/pegawai/${p.id}/edit', true);
        $this->assertPimpinanIndexSee($user, 'Tambah Manual', false);
        $this->assertPimpinanIndexSee($user, 'Import Pegawai', false);
        $this->assertPimpinanIndexSee($user, 'SK Wajib', false);
        $this->assertPimpinanIndexSee($user, 'deletePegawai(p.id', false);

        $this->actingAs($user)->get(route('rbac.pegawai.edit', $employee))->assertOk();
        $this->actingAs($user)->get(route('rbac.pegawai.create'))->assertForbidden();
    }

    public function test_pimpinan_hanya_deactivate_melihat_nonaktifkan_tidak_edit(): void
    {
        $user = $this->pimpinanWithPermissions(['employees.deactivate']);
        $employee = Employee::factory()->create();

        $this->assertPimpinanIndexSee($user, 'Nonaktifkan Pegawai', true);
        $this->assertPimpinanIndexSee($user, '/rbac/pegawai/'.$employee->id.'/edit', false);
        $this->assertPimpinanIndexSee($user, 'Tambah Manual', false);

        // API delete should be allowed via permission+scope (validation may be 422, but not 403)
        $response = $this->actingAs($user)->deleteJson(route('api.v1.pegawai.destroy', $employee));
        $this->assertNotEquals(403, $response->status());
    }

    public function test_pimpinan_hanya_restore_melihat_restore_tidak_edit(): void
    {
        $employee = Employee::factory()->create();
        // Buat employee nonaktif untuk test restore
        $employee->status_pegawai_id = RefStatusPegawai::where('kode', 'NONAKTIF')->value('id') ?? $employee->status_pegawai_id;
        $employee->save();

        $user = $this->pimpinanWithPermissions(['employees.restore']);

        $this->assertPimpinanIndexSee($user, 'Aktifkan Kembali', true);
        $this->assertPimpinanIndexSee($user, '/rbac/pegawai/${p.id}/edit', false);
        $this->assertPimpinanIndexSee($user, 'Tambah Manual', false);
    }

    public function test_pimpinan_hanya_sk_manage_melihat_sk_tidak_edit(): void
    {
        $user = $this->pimpinanWithPermissions(['sk_requirements.manage']);

        $this->assertPimpinanIndexSee($user, 'SK Wajib', true);
        $this->assertPimpinanIndexSee($user, '/rbac/pegawai/${p.id}/edit', false);
        $this->assertPimpinanIndexSee($user, 'deletePegawai(p.id', false);
        $this->assertPimpinanIndexSee($user, 'Tambah Manual', false);

        // RBAC sk-requirements should be reachable (not 403) — validation may be 302/422 but not forbidden
        $response = $this->actingAs($user)->post(route('rbac.sk-requirements.update'), ['matrix' => ['dummy' => ['sk1']]]);
        $this->assertNotEquals(403, $response->status());
        $this->actingAs($user)->get(route('pegawai.create'))->assertForbidden();
    }

    public function test_pimpinan_hanya_export_melihat_export_tidak_edit(): void
    {
        $user = $this->pimpinanWithPermissions(['employees.export', 'employees.read']);

        $this->assertPimpinanIndexSee($user, 'Export Excel', true);
        $this->assertPimpinanIndexSee($user, 'Export PDF', true);
        $this->assertPimpinanIndexSee($user, '/rbac/pegawai/', false);
        $this->assertPimpinanIndexSee($user, 'SK Wajib', false);
    }

    public function test_pimpinan_tanpa_mutasi_tetap_read_only(): void
    {
        $user = $this->pimpinanWithPermissions([]);
        Employee::factory()->create();

        $response = $this->actingAs($user)->get(route('pimpinan.pegawai.index'));
        $response->assertOk()
            ->assertDontSee('deletePegawai', false)
            ->assertDontSee('restorePegawai', false)
            ->assertDontSee('SK Wajib', false)
            ->assertDontSee('Tambah Manual', false)
            ->assertDontSee('/rbac/pegawai/', false)
            ->assertDontSee('Export Excel', false);
    }

    public function test_pimpinan_create_tidak_membuka_restore(): void
    {
        $user = $this->pimpinanWithPermissions(['employees.create']);
        $this->assertPimpinanIndexSee($user, 'restorePegawai(p.id', false);
    }

    public function test_pimpinan_export_tidak_membuka_edit(): void
    {
        $user = $this->pimpinanWithPermissions(['employees.export', 'employees.read']);
        $employee = Employee::factory()->create();
        $this->assertPimpinanIndexSee($user, '/rbac/pegawai/${p.id}/edit', false);
    }

    public function test_rendered_links_tidak_403_untuk_permission_yang_diberi(): void
    {
        $employee = Employee::factory()->create();

        $userCreate = $this->pimpinanWithPermissions(['employees.create']);
        $this->actingAs($userCreate)->get(route('rbac.pegawai.create'))->assertOk();

        $userUpdate = $this->pimpinanWithPermissions(['employees.update']);
        $this->actingAs($userUpdate)->get(route('rbac.pegawai.edit', $employee))->assertOk();

        $userSk = $this->pimpinanWithPermissions(['sk_requirements.manage']);
        $response = $this->actingAs($userSk)->post(route('rbac.sk-requirements.update'), ['matrix' => ['dummy' => ['sk1']]]);
        $this->assertNotEquals(403, $response->status());
    }
}
