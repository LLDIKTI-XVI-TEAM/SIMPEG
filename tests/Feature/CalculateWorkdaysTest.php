<?php

namespace Tests\Feature;

use App\Models\RefHariLibur;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji endpoint kalkulasi hari kerja yang dipakai form pengajuan cuti.
 * Memastikan kalkulasi benar, peringatan muncul, dan gerbang RBAC (permission cuti.create) ditegakkan.
 */
class CalculateWorkdaysTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/cuti/calculate-workdays';

    protected function setUp(): void
    {
        parent::setUp();

        // Seed RBAC agar permission cuti.create tersedia untuk middleware permission.
        $this->seed(RbacSeeder::class);
    }

    public function test_pegawai_dapat_menghitung_hari_kerja(): void
    {
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'?start=2026-01-05&end=2026-01-09');

        $response->assertOk();
        $response->assertJsonPath('data.jumlah_hari_kerja', 5);
        $response->assertJsonPath('data.warnings', []);
    }

    public function test_kalkulasi_mengurangi_libur_dan_cuti_bersama(): void
    {
        $user = User::factory()->pegawai()->create();
        RefHariLibur::create([
            'tanggal' => '2026-01-07',
            'nama' => 'Libur Nasional',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'?start=2026-01-05&end=2026-01-09');

        $response->assertOk();
        $response->assertJsonPath('data.jumlah_hari_kerja', 4);
    }

    public function test_endpoint_mengembalikan_peringatan_akhir_pekan(): void
    {
        $user = User::factory()->pegawai()->create();

        // 2026-01-03 = Sabtu, harus memunculkan peringatan tanggal mulai.
        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'?start=2026-01-03&end=2026-01-09');

        $response->assertOk();
        $response->assertJsonCount(1, 'data.warnings');
    }

    public function test_super_admin_dapat_mengakses(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'?start=2026-01-05&end=2026-01-09');

        $response->assertOk();
    }

    public function test_admin_kepegawaian_tidak_dapat_mengakses(): void
    {
        // Admin kepegawaian tidak memegang cuti.create sehingga ditolak gate permission.
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'?start=2026-01-05&end=2026-01-09');

        $response->assertForbidden();
    }

    public function test_kepala_bagian_tidak_dapat_mengakses(): void
    {
        $user = User::factory()->kepalaBagian()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'?start=2026-01-05&end=2026-01-09');

        $response->assertForbidden();
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $response = $this->getJson(self::ENDPOINT.'?start=2026-01-05&end=2026-01-09');

        $response->assertRedirect('/login');
    }

    public function test_menolak_tanggal_selesai_sebelum_mulai(): void
    {
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'?start=2026-01-09&end=2026-01-05');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['end']);
    }

    public function test_menolak_format_tanggal_tidak_valid(): void
    {
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT.'?start=05-01-2026&end=2026-01-09');

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['start']);
    }

    public function test_menolak_parameter_kosong(): void
    {
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::ENDPOINT);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['start', 'end']);
    }
}
