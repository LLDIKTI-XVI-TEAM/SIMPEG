<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EmployeeDeactivateRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
    }

    public function test_employee_deactivate_and_restore_permissions_are_seeded(): void
    {
        $this->assertDatabaseHas('permissions', [
            'name' => 'employees.deactivate',
            'module' => 'employees',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'employees.restore',
            'module' => 'employees',
        ]);

        $admin = User::factory()->adminKepegawaian()->create();

        $this->assertTrue($admin->hasPermission('employees.deactivate'));
        $this->assertTrue($admin->hasPermission('employees.restore'));
    }

    public function test_admin_kepegawaian_can_deactivate_employee_via_api_and_audit_is_written(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif API',
            'status_aktif' => 'Aktif',
        ]);

        $this->actingAs($user);
        $response = $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Data pegawai berhasil dinonaktifkan.');
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'SOFT_DELETE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_super_admin_can_deactivate_employee_via_api(): void
    {
        $user = User::factory()->superAdmin()->create();
        $employee = Employee::factory()->create(['status_aktif' => 'Aktif']);

        $this->actingAs($user)
            ->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}")
            ->assertOk();

        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
    }

    public function test_admin_kepegawaian_melihat_aksi_nonaktifkan_di_daftar_pegawai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertSee('Nonaktifkan', false)
            ->assertSee('Tampilkan Pegawai Non-Aktif', false)
            ->assertSee('Data tidak dihapus dan bisa diaktifkan kembali.', false)
            ->assertDontSee('30 hari', false)
            ->assertDontSee('dihapus permanen otomatis', false);
    }

    public function test_admin_kepegawaian_melihat_aksi_nonaktifkan_di_detail_pegawai(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Detail Nonaktif']);

        $this->actingAs($user)
            ->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertSee('Nonaktifkan', false)
            ->assertSee('Apakah Anda yakin ingin menonaktifkan pegawai', false)
            ->assertSee('Data tidak dihapus dan bisa diaktifkan kembali.', false);
    }

    public function test_tombol_nonaktifkan_tidak_tampil_tanpa_permission(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.deactivate')->value('id');
        $role->permissions()->detach($permissionId);
        $employee = Employee::factory()->create();

        $this->actingAs($user)
            ->get(route('data-pegawai'))
            ->assertOk()
            ->assertDontSee('aria-label="Nonaktifkan pegawai', false);

        $this->get(route('pegawai.show', $employee->id))
            ->assertOk()
            ->assertDontSee('Nonaktifkan', false);
    }

    public function test_admin_can_restore_employee_via_api_and_audit_is_written(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Restore API',
            'status_aktif' => 'Aktif',
        ]);
        $employee->delete();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/restore");

        $response->assertOk();
        $response->assertJsonPath('message', 'Data pegawai berhasil diaktifkan kembali.');
        $response->assertJsonPath('employee.id', $employee->id);
        $response->assertJsonMissingPath('employee.nik');
        $response->assertJsonMissingPath('employee.no_kk');
        $response->assertJsonMissingPath('employee.keycloak_id');
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'RESTORE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_api_inactive_list_only_returns_trashed_employees(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif']);
        $inactive = Employee::factory()->create(['nama_lengkap' => 'Pegawai Nonaktif']);
        $inactive->delete();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/pegawai/nonaktif');

        $response->assertOk();
        $response->assertJsonPath('employees.data.0.nama_lengkap', 'Pegawai Nonaktif');
        $response->assertJsonMissingPath('employees.data.0.nik');
        $response->assertJsonMissingPath('employees.data.0.no_kk');
        $response->assertJsonMissingPath('employees.data.0.keycloak_id');
        $response->assertJsonMissing(['nama_lengkap' => 'Pegawai Aktif']);
    }

    public function test_api_employee_list_can_filter_nonaktif_employees(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif Filter']);
        $inactive = Employee::factory()->create(['nama_lengkap' => 'Pegawai Nonaktif Filter']);
        $inactive->delete();

        $this->actingAs($user)
            ->getJson('/api/v1/pegawai?show_nonaktif=1')
            ->assertOk()
            ->assertJsonPath('employees.data.0.nama_lengkap', 'Pegawai Nonaktif Filter')
            ->assertJsonMissing(['nama_lengkap' => 'Pegawai Aktif Filter']);
    }

    public function test_web_deactivate_redirects_and_writes_soft_delete_audit(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Web Nonaktif']);

        $this->actingAs($user);
        $response = $this->postWithCsrf(route('pegawai.destroy', $employee->id));

        $response->assertRedirect(route('data-pegawai'));
        $this->assertSoftDeleted('employees', ['id' => $employee->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'SOFT_DELETE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_web_restore_redirects_and_writes_restore_audit(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $employee = Employee::factory()->create(['nama_lengkap' => 'Pegawai Web Restore']);
        $employee->delete();

        $this->actingAs($user);
        $response = $this->postWithCsrf(route('pegawai.restore', $employee->id));

        $response->assertRedirect(route('data-backup'));
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'RESTORE',
            'auditable_type' => 'Employee',
            'auditable_id' => $employee->id,
        ]);
    }

    public function test_inactive_page_renders_database_trashed_employees_not_static_rows(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        Employee::factory()->create(['nama_lengkap' => 'Pegawai Aktif Tidak Muncul']);
        $inactive = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Nonaktif Database',
            'nip' => '198001012006041001',
            'jabatan_terakhir' => 'Analis Kepegawaian',
            'golongan_terakhir' => 'III/a',
        ]);
        $inactive->delete();

        $this->actingAs($user);
        $response = $this->get(route('data-nonaktif'));

        $response->assertOk();
        $response->assertSeeText('Pegawai Nonaktif Database');
        $response->assertSeeText('198001012006041001');
        $response->assertDontSeeText('Pegawai Aktif Tidak Muncul');
        $response->assertDontSeeText('Yucna Dara');
        $response->assertSee(route('pegawai.restore', $inactive->id), false);
    }

    public function test_pegawai_cannot_deactivate_or_restore_employee(): void
    {
        $user = User::factory()->pegawai()->create();
        $employee = Employee::factory()->create();
        $trashed = Employee::factory()->create();
        $trashed->delete();

        $this->actingAs($user);

        $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}")->assertForbidden();
        $this->postJsonWithCsrf("/api/v1/pegawai/{$trashed->id}/restore")->assertForbidden();
    }

    public function test_permission_removal_blocks_deactivate_and_restore(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionIds = Permission::whereIn('name', [
            'employees.deactivate',
            'employees.restore',
        ])->pluck('id');
        $role->permissions()->detach($permissionIds);
        $employee = Employee::factory()->create();
        $trashed = Employee::factory()->create();
        $trashed->delete();

        $this->actingAs($user);

        $this->deleteJsonWithCsrf("/api/v1/pegawai/{$employee->id}")->assertForbidden();
        $this->postJsonWithCsrf("/api/v1/pegawai/{$trashed->id}/restore")->assertForbidden();
    }

    private function postJsonWithCsrf(string $uri): TestResponse
    {
        return $this
            ->withSession(['_token' => 'test-token'])
            ->postJson($uri, ['_token' => 'test-token']);
    }

    private function postWithCsrf(string $uri): TestResponse
    {
        return $this
            ->withSession(['_token' => 'test-token'])
            ->post($uri, ['_token' => 'test-token']);
    }

    private function deleteJsonWithCsrf(string $uri): TestResponse
    {
        return $this
            ->withSession(['_token' => 'test-token'])
            ->deleteJson($uri, ['_token' => 'test-token']);
    }
}
