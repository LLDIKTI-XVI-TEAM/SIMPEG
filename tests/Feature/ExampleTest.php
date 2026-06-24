<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

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
        $user = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($user)->get('/dashboard/pengaturan');

        $response->assertOk();
        $response->assertSee('Umum & Instansi', false);
        $response->assertSee('Alur Approval Cuti');
    }

    public function test_settings_redirects(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($user)->get('/pengaturan');
        $response->assertRedirect('/dashboard/pengaturan');

        $responseCap = $this->actingAs($user)->get('/dashboard/Pengaturan');
        $responseCap->assertRedirect('/dashboard/pengaturan');
    }

    public function test_settings_aborts_for_non_super_admin(): void
    {
        $user = User::factory()->create(['role' => 'pegawai']);
        session(['active_role' => 'pegawai']);

        $response = $this->actingAs($user)->get('/dashboard/pengaturan');
        $response->assertStatus(403);
    }

    public function test_authenticated_export_cuti_renders(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get('/laporan/export-cuti');

        $response->assertOk();
        $response->assertSee('Laporan');
        $response->assertSee('Export Rekap Cuti');
        $response->assertSee('Ahmad Fauzi');
    }

    public function test_authenticated_export_cuti_excel(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get('/laporan/export-cuti/excel');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Content-Disposition', 'attachment; filename=Rekap_Cuti_Semua_Periode_' . now()->format('Ymd') . '.xlsx');
    }

    public function test_authenticated_export_pegawai_renders(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get('/laporan/export-pegawai');

        $response->assertOk();
        $response->assertSee('Daftar Nominatif Pegawai');
        $response->assertSee('Ahmad Fauzi');
    }

    public function test_authenticated_export_pegawai_excel(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($user)->get('/laporan/export-pegawai/excel');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Content-Disposition', 'attachment; filename=Daftar_Pegawai_LLDIKTI_XVI_' . now()->format('Ymd') . '.xlsx');
    }

    public function test_authenticated_user_management_renders(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($user)->get('/user-management');

        $response->assertOk();
        $response->assertSee('User Management');
        $response->assertSee('Ahmad Fauzi');
    }

    public function test_user_management_update_mapping_success(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($admin)
            ->from('/user-management')
            ->post('/user-management/update', [
                'email' => 'ahmadfauzi@gmail.com',
                'keycloak_id' => 'keycloak-fauzi-99',
                'role' => 'admin_kepegawaian',
            ]);

        $response->assertRedirect('/user-management');
        $response->assertSessionHas('success', 'Akses User berhasil diperbarui!');

        $this->assertDatabaseHas('users', [
            'email' => 'ahmadfauzi@gmail.com',
            'keycloak_id' => 'keycloak-fauzi-99',
            'role' => 'admin_kepegawaian',
        ]);

        $dynamicLogs = session('dynamic_audit_logs', []);
        $this->assertNotEmpty($dynamicLogs);
        $this->assertEquals('UPDATE_MAPPING', $dynamicLogs[0]['event']);
    }

    public function test_user_management_rejects_duplicate_keycloak_id(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);
        
        // Mapped user 1
        User::factory()->create([
            'email' => 'ahmadfauzi@gmail.com',
            'keycloak_id' => 'shared-keycloak-id',
            'role' => 'pegawai',
        ]);

        $response = $this->actingAs($admin)
            ->from('/user-management')
            ->post('/user-management/update', [
                'email' => 'sitirahayu@gmail.com',
                'keycloak_id' => 'shared-keycloak-id',
                'role' => 'atasan_langsung',
            ]);

        $response->assertRedirect('/user-management');
        $response->assertSessionHas('error', 'Keycloak ID tersebut sudah digunakan oleh pegawai lain!');
    }

    public function test_authenticated_rbac_renders_for_super_admin(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($admin)->get('/rbac');

        $response->assertOk();
        $response->assertSee('Matriks Konfigurasi RBAC');
        $response->assertSee('employees.create');
    }

    public function test_rbac_aborts_for_non_super_admin(): void
    {
        $pegawai = User::factory()->create(['role' => 'pegawai']);
        session(['active_role' => 'pegawai']);

        $response = $this->actingAs($pegawai)->get('/rbac');

        $response->assertStatus(403);
    }

    public function test_rbac_update_permissions_success(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        // Let's modify permissions for role Admin Kepegawaian
        $role = \App\Models\Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissions = \App\Models\Permission::limit(3)->pluck('id')->toArray();
        $roleId = $role->id;

        $response = $this->actingAs($admin)
            ->from('/rbac')
            ->post('/rbac/update', [
                'matrix' => [
                    $roleId => $permissions
                ]
            ]);

        $response->assertRedirect('/rbac');
        $response->assertSessionHas('success', 'Hak akses peran (RBAC) berhasil diperbarui!');

        // Check if database table role_permissions has exactly these mappings for role_id
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $roleId,
            'permission_id' => $permissions[0]
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $roleId,
            'permission_id' => $permissions[1]
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $roleId,
            'permission_id' => $permissions[2]
        ]);

        // Check audit log
        $dynamicLogs = session('dynamic_audit_logs', []);
        $this->assertNotEmpty($dynamicLogs);
        $this->assertEquals('UPDATE_RBAC', $dynamicLogs[0]['event']);
    }


    public function test_unauthorized_access_to_ews_config_aborts(): void
    {
        $user = User::factory()->create(['role' => 'pegawai']);
        session(['active_role' => 'pegawai']);

        $response = $this->actingAs($user)->get('/konfigurasi');
        $response->assertStatus(403);

        $responsePost = $this->actingAs($user)->post('/konfigurasi/update', [
            'ews_scheduler_time' => '07:30',
            'reason' => 'mencoba meretas',
        ]);
        $responsePost->assertStatus(403);
    }

    public function test_authorized_super_admin_can_view_ews_config(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($admin)->get('/konfigurasi');
        $response->assertOk();
        $response->assertSee('Konfigurasi Early Warning System (EWS)');
        $response->assertSee('07:00');
    }

    public function test_super_admin_can_update_ews_config(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($admin)
            ->from('/konfigurasi')
            ->post('/konfigurasi/update', [
                'ews_scheduler_time' => '08:30',
                'pangkat_h90' => '95',
                'pangkat_h60' => '65',
                'pangkat_h30' => '35',
                'kgb_h60' => '60',
                'kgb_h30' => '30',
                'kgb_h14' => '14',
                'pensiun_y1' => '365',
                'pensiun_m6' => '180',
                'pensiun_m3' => '90',
                'pppk_m6' => '180',
                'pppk_m3' => '90',
                'pppk_m1' => '30',
                'reason' => 'Testing update konfigurasi EWS harian',
            ]);

        $response->assertRedirect('/konfigurasi');
        $response->assertSessionHas('success', 'Konfigurasi EWS berhasil diperbarui.');

        $this->assertDatabaseHas('ews_configs', [
            'key' => 'ews_scheduler_time',
            'value' => '08:30',
        ]);
        $this->assertDatabaseHas('ews_configs', [
            'key' => 'pangkat_h90',
            'value' => '95',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'EwsConfig',
            'auditable_id' => null,
        ]);

        $dynamicLogs = session('dynamic_audit_logs', []);
        $this->assertNotEmpty($dynamicLogs);

        // Find UPDATE_EWS_CONFIG event
        $foundEwsLog = false;
        foreach ($dynamicLogs as $log) {
            if ($log['event'] === 'UPDATE_EWS_CONFIG' && $log['record_id'] === 'Scheduler Time') {
                $foundEwsLog = true;
                $this->assertEquals('07:00', $log['old_values']['value']);
                $this->assertEquals('08:30', $log['new_values']['value']);
                $this->assertEquals('Testing update konfigurasi EWS harian', $log['new_values']['reason']);
            }
        }
        $this->assertTrue($foundEwsLog);
    }

    public function test_unauthorized_access_to_ews_active_aborts(): void
    {
        $user = User::factory()->create(['role' => 'pegawai']);
        session(['active_role' => 'pegawai']);

        $response = $this->actingAs($user)->get('/ews');
        $response->assertStatus(403);
    }

    public function test_authorized_user_can_view_ews_active(): void
    {
        $admin = User::factory()->create(['role' => 'admin_kepegawaian']);
        session(['active_role' => 'admin_kepegawaian']);

        // Seed two employees with EWS alerts so the page renders real data
        $fauzi = Employee::factory()->create(['nama_lengkap' => 'Ahmad Fauzi']);
        EwsAlert::create([
            'employee_id'   => $fauzi->id,
            'type'          => 'KGB',
            'target_date'   => now()->addDays(45)->toDateString(),
            'interval_days' => 60,
            'is_processed'  => false,
        ]);

        $cimma = Employee::factory()->create(['nama_lengkap' => 'Cimma Sari Oktariani Di Silapu']);
        EwsAlert::create([
            'employee_id'   => $cimma->id,
            'type'          => 'KENAIKAN_PANGKAT',
            'target_date'   => now()->addDays(70)->toDateString(),
            'interval_days' => 90,
            'is_processed'  => false,
        ]);

        $response = $this->actingAs($admin)->get('/ews');
        $response->assertOk();
        $response->assertSee('Daftar EWS Aktif');
        $response->assertSee('Ahmad Fauzi');
        $response->assertSee('Cimma Sari Oktariani Di Silapu');
    }

    public function test_ews_active_filtering(): void
    {
        $admin = User::factory()->create(['role' => 'admin_kepegawaian']);
        session(['active_role' => 'admin_kepegawaian']);

        // Seed employees with specific alert types for filtering
        $fauzi = Employee::factory()->create(['nama_lengkap' => 'Ahmad Fauzi']);
        EwsAlert::create([
            'employee_id'   => $fauzi->id,
            'type'          => 'KGB',
            'target_date'   => now()->addDays(45)->toDateString(),
            'interval_days' => 60,
            'is_processed'  => false,
        ]);

        $cimma = Employee::factory()->create(['nama_lengkap' => 'Cimma Sari Oktariani Di Silapu']);
        EwsAlert::create([
            'employee_id'   => $cimma->id,
            'type'          => 'KENAIKAN_PANGKAT',
            'target_date'   => now()->addDays(70)->toDateString(),
            'interval_days' => 90,
            'is_processed'  => false,
        ]);

        // Filter for KGB
        $response = $this->actingAs($admin)->get('/ews?event=KGB');
        $response->assertOk();
        $response->assertSee('Ahmad Fauzi'); // Ahmad Fauzi has a KGB alert
        $response->assertDontSee('Cimma Sari Oktariani Di Silapu'); // Cimma has a Kenaikan Pangkat alert
    }

    public function test_unauthorized_access_to_hari_libur_aborts(): void
    {
        $user = User::factory()->create(['role' => 'pegawai']);
        session(['active_role' => 'pegawai']);

        $response = $this->actingAs($user)->get('/hari-libur');
        $response->assertStatus(403);
    }

    public function test_authorized_super_admin_can_view_hari_libur(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($admin)->get('/hari-libur');
        $response->assertOk();
        $response->assertSee('Hari Libur');
    }

    public function test_super_admin_can_create_holiday(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($admin)->post('/hari-libur', [
            'tanggal' => '2026-08-17',
            'nama' => 'Test Hari Kemerdekaan',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertRedirect('/hari-libur');
        $response->assertSessionHas('success');

        $dynamicLogs = session('dynamic_audit_logs', []);
        $this->assertNotEmpty($dynamicLogs);
        
        $found = false;
        foreach ($dynamicLogs as $log) {
            if ($log['event'] === 'CREATE_HOLIDAY' && $log['record_id'] === 'Test Hari Kemerdekaan') {
                $found = true;
                $this->assertEquals('2026-08-17', $log['new_values']['tanggal']);
            }
        }
        $this->assertTrue($found);
    }

    public function test_super_admin_can_update_holiday(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($admin)->post('/hari-libur/1', [
            'tanggal' => '2026-01-02',
            'nama' => 'Updated Tahun Baru',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertRedirect('/hari-libur');
        $response->assertSessionHas('success');

        $dynamicLogs = session('dynamic_audit_logs', []);
        $this->assertNotEmpty($dynamicLogs);
        
        $found = false;
        foreach ($dynamicLogs as $log) {
            if ($log['event'] === 'UPDATE_HOLIDAY' && $log['record_id'] === 'Updated Tahun Baru') {
                $found = true;
                $this->assertEquals('2026-01-02', $log['new_values']['tanggal']);
            }
        }
        $this->assertTrue($found);
    }

    public function test_super_admin_can_delete_holiday(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        session(['active_role' => 'super_admin']);

        $response = $this->actingAs($admin)->post('/hari-libur/1/delete');

        $response->assertRedirect('/hari-libur');
        $response->assertSessionHas('success');

        $dynamicLogs = session('dynamic_audit_logs', []);
        $this->assertNotEmpty($dynamicLogs);
        
        $found = false;
        foreach ($dynamicLogs as $log) {
            if ($log['event'] === 'DELETE_HOLIDAY') {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }
}
