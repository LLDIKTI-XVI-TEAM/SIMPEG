<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminKepegawaianAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_sidebar_admin_kepegawaian_menampilkan_menu_universal_tanpa_menu_super_admin(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'admin_kepegawaian'])
            ->get('/dashboard');

        $response->assertOk();

        // Menu yang diizinkan untuk admin_kepegawaian memiliki tautan href aktif.
        foreach ([
            'data-pegawai',
            'dokumen',
            'cuti.rekap',
            'ews',
            'laporan.pegawai',
            'cuti.laporan',
            'audit-log',
        ] as $route) {
            $response->assertSee('href="'.route($route).'"', false);
        }

        // Menu tanpa izin akses tampil disabled tanpa atribut href aktif.
        // Kode: cuti.config terlihat untuk admin (canConfigureLeave=true via cuti.configure),
        // hari-libur selalu dirender unconditional (app.blade.php:182) namun dikunci via lockedMenus.
        // Test mengikuti kode: cuti.config diharapkan terlihat.
        foreach ([
            'user-management',
            'rbac',
            'data-master',
            'hari-libur',
            'ews.config',
        ] as $forbiddenRoute) {
            $response->assertDontSee('href="'.route($forbiddenRoute).'"', false);
        }

        $response->assertSee('href="'.route('cuti.config').'"', false);

        foreach ([
            'data-pegawai',
            'dokumen',
            'cuti.rekap',
            'cuti.cancellations.index',
            'ews',
            'laporan.pegawai',
            'cuti.laporan',
            'audit-log',
        ] as $allowedRoute) {
            $response->assertSee('href="'.route($allowedRoute).'"', false);
        }
    }

    public function test_admin_kepegawaian_tidak_dapat_membuka_halaman_khusus_super_admin(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin)->withSession(['active_role' => 'admin_kepegawaian']);

        foreach ([
            '/user-management',
            '/rbac',
            '/data-master',
            '/konfigurasi',
        ] as $uri) {
            $this->get($uri)->assertForbidden();
        }

        // Kode: hari_libur.read adalah PATEN user-context (PatenCapability::USER_CONTEXT),
        // sehingga halaman tetap 200 walau disembunyikan dari sidebar via lockedMenus.
        $this->get('/hari-libur')->assertOk();
    }

    public function test_super_admin_mempertahankan_surface_konfigurasi_kanonis_tanpa_menu_placeholder(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $dashboard = $this->actingAs($superAdmin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('dashboard'));

        $dashboard->assertOk();
        $dashboard->assertDontSee('Pengaturan Sistem');
        $dashboard->assertSee('href="'.route('cuti.cancellations.index').'"', false);

        foreach ([
            'user-management',
            'rbac',
            'data-master',
            'hari-libur',
            'ews.config',
            'cuti.config',
            'data-master.channel-notifikasi.index',
        ] as $routeName) {
            $this->get(route($routeName))->assertOk();
        }
    }

    public function test_sidebar_pembatalan_cuti_mengikuti_grant_dan_revoke_permission_super_admin(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $superAdminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $cancellationPermission = Permission::query()
            ->where('name', 'cuti.cancellation.manage')
            ->firstOrFail();

        $superAdminRole->permissions()->syncWithoutDetaching([$cancellationPermission->id]);

        $dashboard = $this->actingAs($superAdmin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('dashboard'));

        $dashboard->assertOk();
        $dashboard->assertSee('href="'.route('cuti.cancellations.index').'"', false);
        $this->get(route('cuti.cancellations.index'))->assertOk();

        $superAdminRole->permissions()->detach($cancellationPermission->id);
        $this->get(route('dashboard'))
            ->assertDontSee('href="'.route('cuti.cancellations.index').'"', false);
        $this->get(route('cuti.cancellations.index'))->assertForbidden();
    }

    public function test_super_admin_dapat_membuka_konfigurasi_approval_cuti_tanpa_menu_pengaturan_legacy(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $dashboardResponse = $this->actingAs($superAdmin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('dashboard'));

        $dashboardResponse->assertOk();
        $dashboardResponse->assertDontSee('Pengaturan Sistem');

        $this->get(route('cuti.config'))->assertOk();

    }

    public function test_sidebar_menampilkan_semua_menu_yang_capability_permissionnya_tersedia(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->get(route('dashboard'));

        $response->assertOk();

        foreach ([
            'notifications.index',
            'hari-libur',
            'cuti.config',
        ] as $routeName) {
            $response->assertSee('href="'.route($routeName).'"', false);
        }
    }

    public function test_app_shell_menyediakan_semantik_aksesibilitas_untuk_navigasi_pencarian_menu_dan_toast(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)
            ->withSession(['active_role' => 'super_admin'])
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('x-ref="sidebarNav"', false);
        $response->assertSee(':inert="isMobileNavigation && !sidebarOpen"', false);
        $response->assertSee('@keydown.escape.window="closeSidebar()"', false);
        $response->assertSee('aria-label="Pencarian global"', false);
        $response->assertSee(':aria-expanded="open.toString()"', false);
        $response->assertSee('aria-controls="profile-menu"', false);
        $response->assertSee('role="menu"', false);
        $response->assertSee('aria-controls="role-switch-menu"', false);
        $response->assertSee(':role="toast.type === \'error\' ? \'alert\' : \'status\'"', false);
    }

    public function test_sidebar_menyembunyikan_rbac_yang_dicabut_tetapi_mempertahankan_paten(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $permissionIds = Permission::query()
            ->whereIn('name', [
                'employees.read',
                'dokumen_sk.read',
                'notifications.read',
                'audit_logs.read',
                'hari_libur.read',
                'cuti.configure',
            ])
            ->pluck('id')
            ->all();

        $role->permissions()->detach($permissionIds);

        $response = $this->actingAs($superAdmin)
            ->get(route('dashboard'));

        $response->assertOk();
        $sidebar = Str::between($response->getContent(), '<nav id="sidebar-nav"', '</nav>');

        foreach ([
            'data-pegawai',
            'dokumen',
            'reporting.employee-statistics',
            'audit-log',
            'cuti.config',
        ] as $routeName) {
            $this->assertStringNotContainsString('href="'.route($routeName).'"', $sidebar);
        }

        $this->get(route('data-pegawai'))->assertForbidden();
        foreach (['notifications.index', 'hari-libur'] as $routeName) {
            $this->assertStringContainsString('href="'.route($routeName).'"', $sidebar);
            $this->get(route($routeName))->assertOk();
        }
    }

    public function test_sidebar_arsip_mengikuti_permission_dokumen_tanpa_memerlukan_baca_pegawai(): void
    {
        $actor = User::factory()->adminKepegawaian()->create();
        $role = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('name', 'employees.read')->value('id'));
        $documentPermission = Permission::query()->where('name', 'dokumen_sk.read')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$documentPermission->id]);

        $response = $this->actingAs($actor)->get(route('dashboard'));
        $response->assertOk()->assertSee('href="'.route('dokumen').'"', false);
        $this->get(route('dokumen'))->assertOk();

        $role->permissions()->detach($documentPermission->id);
        $this->get(route('dashboard'))->assertDontSee('href="'.route('dokumen').'"', false);
        $this->get(route('dokumen'))->assertForbidden();
    }

    public function test_sidebar_menampilkan_statistik_saat_permission_diberikan_secara_dinamis(): void
    {
        $pegawai = User::factory()->pegawai()->create();
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();
        $employeesReadId = Permission::query()
            ->where('name', 'employees.read')
            ->firstOrFail()
            ->id;

        $role->permissions()->syncWithoutDetaching([$employeesReadId]);

        $response = $this->actingAs($pegawai)
            ->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('href="'.route('reporting.employee-statistics').'"', false);
        $this->get(route('reporting.employee-statistics'))->assertOk();
    }

    public function test_admin_kepegawaian_dapat_membuka_halaman_operasional_sesuai_dokumen(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin)->withSession(['active_role' => 'admin_kepegawaian']);

        foreach ([
            '/dashboard',
            '/pegawai',
            '/pegawai/import-data',
            '/dashboard/dokumen',
            '/cuti/rekap',
            '/ews',
            '/laporan/export-pegawai',
            '/cuti/laporan',
            '/dashboard/audit',
            '/notifikasi',
        ] as $uri) {
            $response = $this->get($uri);
            if ($response->status() !== 200) {
                dump("Failed on URI: $uri, Status: ".$response->status());
            }
            $response->assertOk();
        }
    }

    public function test_rekap_cuti_menyediakan_tautan_export_tanpa_preview_laporan_palsu(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'admin_kepegawaian'])
            ->get('/cuti/rekap');

        $response->assertOk();
        $response->assertSee('Buka Laporan & Export', false);
        $response->assertSee(route('cuti.laporan'), false);
        $response->assertDontSee('/laporan/export-cuti', false);
        $response->assertDontSee('Input Saldo Awal', false);
        $response->assertDontSee('/cuti/administrasi-saldo/00000000-0000-0000-0000-000000000000/rekonsiliasi', false);
        $response->assertDontSee(route('cuti.manual.store', ['employee' => '00000000-0000-0000-0000-000000000000']), false);
        $response->assertDontSee('activeFilters', false);
        $response->assertDontSee('Preview PDF resmi', false);
    }

    public function test_session_tidak_dapat_dipakai_untuk_menaikkan_role(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        // Jalur change-role legacy sudah dihapus: satu-satunya sumber role efektif adalah
        // temporary_role pada users, sehingga manipulasi session tidak pernah menaikkan role.
        $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get('/change-role/super_admin')
            ->assertNotFound();

        $this->assertSame('admin_kepegawaian', $admin->fresh()->getEffectiveRole());
    }
}
