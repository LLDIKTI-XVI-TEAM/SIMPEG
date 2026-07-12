<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminKepegawaianAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_sidebar_admin_kepegawaian_hanya_menampilkan_menu_yang_diizinkan(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->get('/dashboard');

        $response->assertOk();

        foreach ([
            'user-management',
            'rbac',
            'data-master',
            'hari-libur',
            'ews.config',
            'pengaturan',
            'data-nonaktif',
        ] as $forbiddenRoute) {
            $response->assertDontSee('href="'.route($forbiddenRoute).'"', false);
        }

        foreach ([
            'data-pegawai',
            'dokumen',
            'cuti.rekap',
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
            '/hari-libur',
            '/konfigurasi',
            '/dashboard/pengaturan',
        ] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_super_admin_dapat_membuka_halaman_pengaturan_sistem(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->withSession(['active_role' => 'super_admin'])
            ->get('/dashboard/pengaturan')
            ->assertOk()
            ->assertSee('Pengaturan Sistem');
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
            '/notifications',
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
        $response->assertSee('Admin Saldo Cuti', false);
        $response->assertDontSee('activeFilters', false);
        $response->assertDontSee('Preview PDF resmi', false);
    }

    public function test_session_tidak_dapat_dipakai_untuk_menaikkan_role(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->withSession(['active_role' => 'admin_kepegawaian'])
            ->get('/change-role/super_admin')
            ->assertForbidden();
    }
}
