<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman pengaturan sistem belum memiliki penyimpanan, sehingga pengirimannya tidak boleh
 * meninggalkan catatan audit apa pun. Mencatat perubahan yang tidak terjadi membuat jejak audit
 * menyesatkan, dan itu lebih berbahaya daripada tidak mencatat.
 */
class SystemSettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_pengiriman_pengaturan_tidak_menulis_audit_palsu(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('settings.update'));

        $response->assertRedirect(route('pengaturan'));
        $response->assertSessionMissing('dynamic_audit_logs');
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_pengiriman_pengaturan_tidak_mengaku_telah_menyimpan(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($superAdmin)->post(route('settings.update'));

        // Formulir belum mengirim satu pun field dan tidak ada penyimpanan di belakangnya, sehingga
        // pesan sukses akan menyesatkan operator seolah konfigurasi sudah berlaku.
        $response->assertSessionMissing('success');
    }

    public function test_peran_lain_tidak_dapat_mengirim_pengaturan(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)->post(route('settings.update'))->assertForbidden();
    }
}
