<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_home_redirects_to_keycloak(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/auth/keycloak/redirect');
    }

    public function test_login_redirects_to_keycloak(): void
    {
        $response = $this->get('/login');
        
        $response->assertRedirect('/auth/keycloak/redirect');
    }

    public function test_authenticated_dashboard_renders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Total Pegawai');
        $response->assertSee($user->name);
    }

    public function test_authenticated_settings_renders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard/pengaturan');

        $response->assertOk();
        $response->assertSee('Umum & Instansi', false);
        $response->assertSee('Alur Approval Cuti');
    }

    public function test_settings_redirects(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/pengaturan');
        $response->assertRedirect('/dashboard/pengaturan');

        $responseCap = $this->actingAs($user)->get('/dashboard/Pengaturan');
        $responseCap->assertRedirect('/dashboard/pengaturan');
    }

    public function test_authenticated_export_cuti_renders(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/laporan/export-cuti');

        $response->assertOk();
        $response->assertSee('Laporan');
        $response->assertSee('Export Rekap Cuti');
        $response->assertSee('Ahmad Fauzi');
    }

    public function test_authenticated_export_cuti_excel(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/laporan/export-cuti/excel');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Content-Disposition', 'attachment; filename=Rekap_Cuti_Semua_Periode_' . now()->format('Ymd') . '.xlsx');
    }

    public function test_authenticated_user_management_renders(): void
    {
        $user = User::factory()->create(['role' => 'Super Admin']);

        $response = $this->actingAs($user)->get('/user-management');

        $response->assertOk();
        $response->assertSee('User Management');
        $response->assertSee('Ahmad Fauzi');
    }

    public function test_user_management_update_mapping_success(): void
    {
        $admin = User::factory()->create(['role' => 'Super Admin']);

        $response = $this->actingAs($admin)
            ->from('/user-management')
            ->post('/user-management/update', [
                'email' => 'ahmadfauzi@gmail.com',
                'keycloak_id' => 'keycloak-fauzi-99',
                'role' => 'Admin Kepegawaian',
            ]);

        $response->assertRedirect('/user-management');
        $response->assertSessionHas('success', 'Akses User berhasil diperbarui!');

        $this->assertDatabaseHas('users', [
            'email' => 'ahmadfauzi@gmail.com',
            'keycloak_id' => 'keycloak-fauzi-99',
            'role' => 'Admin Kepegawaian',
        ]);

        $dynamicLogs = session('dynamic_audit_logs', []);
        $this->assertNotEmpty($dynamicLogs);
        $this->assertEquals('UPDATE_MAPPING', $dynamicLogs[0]['event']);
    }

    public function test_user_management_rejects_duplicate_keycloak_id(): void
    {
        $admin = User::factory()->create(['role' => 'Super Admin']);
        
        // Mapped user 1
        User::factory()->create([
            'email' => 'ahmadfauzi@gmail.com',
            'keycloak_id' => 'shared-keycloak-id',
            'role' => 'Pegawai',
        ]);

        $response = $this->actingAs($admin)
            ->from('/user-management')
            ->post('/user-management/update', [
                'email' => 'sitirahayu@gmail.com',
                'keycloak_id' => 'shared-keycloak-id',
                'role' => 'Atasan Langsung',
            ]);

        $response->assertRedirect('/user-management');
        $response->assertSessionHas('error', 'Keycloak ID tersebut sudah digunakan oleh pegawai lain!');
    }

    public function test_authenticated_rbac_renders_for_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'Super Admin']);
        session(['active_role' => 'Super Admin']);

        $response = $this->actingAs($admin)->get('/rbac');

        $response->assertOk();
        $response->assertSee('Matriks Konfigurasi RBAC');
        $response->assertSee('manage_reference_tables');
    }

    public function test_rbac_aborts_for_non_super_admin(): void
    {
        $pegawai = User::factory()->create(['role' => 'Pegawai']);
        session(['active_role' => 'Pegawai']);

        $response = $this->actingAs($pegawai)->get('/rbac');

        $response->assertStatus(403);
    }

    public function test_rbac_update_permissions_success(): void
    {
        $admin = User::factory()->create(['role' => 'Super Admin']);
        session(['active_role' => 'Super Admin']);

        // Let's modify permissions for role 2 (Admin Kepegawaian)
        $roleId = 2;
        $permissionIds = [1, 2, 3]; // manage_reference_tables, configure_ews, manage_holidays

        $response = $this->actingAs($admin)
            ->from('/rbac')
            ->post('/rbac/update', [
                'matrix' => [
                    $roleId => $permissionIds
                ]
            ]);

        $response->assertRedirect('/rbac');
        $response->assertSessionHas('success', 'Hak akses peran (RBAC) berhasil diperbarui!');

        // Check if database table role_permissions has exactly these mappings for role_id 2
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $roleId,
            'permission_id' => 1
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $roleId,
            'permission_id' => 2
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $roleId,
            'permission_id' => 3
        ]);

        // Check audit log
        $dynamicLogs = session('dynamic_audit_logs', []);
        $this->assertNotEmpty($dynamicLogs);
        $this->assertEquals('UPDATE_RBAC', $dynamicLogs[0]['event']);
    }
}

