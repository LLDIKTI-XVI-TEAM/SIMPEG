<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EwsConfigValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_urutan_threshold_divalidasi_saat_semua_rule_dasar_lulus(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->post('/konfigurasi/update', $this->validPayload([
                // Tahap 1 tidak lebih besar dari Tahap 2: urutan salah.
                'pangkat_h90' => '60',
                'pangkat_h60' => '60',
            ]));

        $response->assertSessionHasErrors(['pangkat_h60']);
    }

    public function test_urutan_threshold_tidak_dicek_saat_rule_dasar_gagal(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        // pangkat_h60 kosong: rule dasar gagal, sehingga pesan urutan pada
        // pangkat_h30 tidak boleh muncul (sama dengan perilaku sebelum refactor).
        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->post('/konfigurasi/update', $this->validPayload([
                'pangkat_h60' => '',
            ]));

        $response->assertSessionHasErrors(['pangkat_h60']);
        $response->assertSessionDoesntHaveErrors(['pangkat_h30']);
    }

    public function test_perubahan_konfigurasi_tercatat_di_basis_data_tanpa_jejak_sesi(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($admin)
            ->withSession(['active_role' => 'super_admin'])
            ->post('/konfigurasi/update', $this->validPayload([
                'pangkat_required_years' => '5',
                'reason' => 'Penyesuaian masa kerja minimum kenaikan pangkat.',
            ]));

        $response->assertSessionHasNoErrors();
        // Catatan berbasis sesi hilang saat pengguna keluar sehingga tidak dapat disebut audit;
        // jejak permanennya harus berada di tabel audit.
        $response->assertSessionMissing('dynamic_audit_logs');
        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'EwsConfig',
        ]);
    }

    public function test_update_konfigurasi_ditolak_untuk_role_selain_super_admin(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();

        $this->actingAs($admin)
            ->withSession(['active_role' => 'admin_kepegawaian'])
            ->post('/konfigurasi/update', $this->validPayload())
            ->assertForbidden();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'ews_scheduler_time' => '07:00',
            'pangkat_required_years' => '4',
            'pangkat_h90' => '90',
            'pangkat_h60' => '60',
            'pangkat_h30' => '30',
            'kgb_required_years' => '2',
            'kgb_h60' => '60',
            'kgb_h30' => '30',
            'kgb_h14' => '14',
            'pensiun_required_age_years' => '0',
            'pensiun_y1' => '365',
            'pensiun_m6' => '180',
            'pensiun_m3' => '90',
            'pppk_contract_years' => '4',
            'pppk_m6' => '180',
            'pppk_m3' => '90',
            'pppk_m1' => '30',
            'satyalancana_years_1' => '10',
            'satyalancana_years_2' => '20',
            'satyalancana_years_3' => '30',
            'satyalancana_h180' => '180',
            'satyalancana_h90' => '90',
            'satyalancana_h30' => '30',
            'reason' => 'Pengujian validasi konfigurasi EWS.',
        ], $overrides);
    }
}
