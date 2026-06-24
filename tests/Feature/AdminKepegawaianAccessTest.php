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
        ] as $forbiddenRoute) {
            $response->assertDontSee('href="' . route($forbiddenRoute) . '"', false);
        }

        foreach ([
            'data-pegawai',
            'pegawai.import',
            'data-nonaktif',
            'dokumen',
            'cuti.rekap',
            'ews',
            'laporan.pegawai',
            'laporan.cuti',
            'audit-log',
        ] as $allowedRoute) {
            $response->assertSee('href="' . route($allowedRoute) . '"', false);
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

    public function test_admin_kepegawaian_dapat_membuka_halaman_operasional_sesuai_dokumen(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $this->actingAs($admin)->withSession(['active_role' => 'admin_kepegawaian']);

        foreach ([
            '/dashboard',
            '/pegawai',
            '/pegawai/import-data',
            '/pegawai/nonaktif-list',
            '/dashboard/dokumen',
            '/cuti/rekap',
            '/ews',
            '/laporan/export-pegawai',
            '/laporan/export-cuti',
            '/dashboard/audit',
            '/notifications',
        ] as $uri) {
            $this->get($uri)->assertOk();
        }
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
