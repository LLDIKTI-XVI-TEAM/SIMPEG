<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menjaga halaman Data Master merender data referensi nyata dari database
 * (bukan array mock statis) untuk tab yang sudah punya CRUD.
 */
class DataMasterPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_melihat_data_referensi_nyata_dari_database(): void
    {
        RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda Uji', 'urutan' => 9]);
        RefJenisJabatan::create(['nama' => 'Fungsional Kekhususan', 'maks_usia_pensiun' => 60]);
        RefEselon::create(['kode' => 'IV.a', 'nama' => 'Eselon Uji IV.a']);
        RefJenjangPendidikan::create(['nama' => 'S3 Terapan Uji', 'urutan' => 10]);
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)->get(route('data-master'));

        $response->assertOk()
            ->assertSee('Penata Muda Uji')
            ->assertSee('Fungsional Kekhususan')
            ->assertSee('Eselon Uji IV.a')
            ->assertSee('S3 Terapan Uji')
            // Baris status pegawai bawaan migration ikut tampil, lengkap
            // dengan penanda baris yang dikunci logika sistem.
            ->assertSee('PERPANJANGAN_CLTN')
            ->assertSee('Data sistem');
    }

    public function test_halaman_tidak_lagi_menampilkan_data_mock_statis(): void
    {
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)->get(route('data-master'));

        // 'Pimpinan Tinggi' dan 'Juru Muda' hanya ada di array mock lama;
        // dengan tabel kosong halaman harus menampilkan empty state.
        $response->assertOk()
            ->assertDontSee('Pimpinan Tinggi')
            ->assertDontSee('Juru Muda')
            ->assertSee('Belum ada data golongan.')
            ->assertSee('Belum ada data jenis jabatan.');
    }

    public function test_jumlah_pemakai_ditampilkan_dari_agregat_database(): void
    {
        $golongan = RefGolongan::create(['kode' => 'IV/a', 'nama' => 'Pembina Uji', 'urutan' => 13]);
        $employee = Employee::factory()->create();
        $employee->rankHistories()->create([
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2020-04-01',
            'no_sk' => 'SK-2020-001',
            'tanggal_sk' => '2020-03-15',
        ]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->get(route('data-master'))
            ->assertOk()
            ->assertSee('1 pemakai');
    }

    public function test_bukan_super_admin_tidak_boleh_membuka_halaman(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->get(route('data-master'))
            ->assertForbidden();
    }
}
