<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenjangPendidikan;
use App\Models\RefUnitKerja;
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
            ->assertSee('Belum ada data jenis jabatan.')
            // Mock unit kerja lama memakai nama kelompok kerja yang tidak ada
            // pada struktur seeder mana pun.
            ->assertDontSee('Kelompok Kerja Akademik dan Kemahasiswaan')
            ->assertDontSee('Kelompok Kerja Kelembagaan dan Sistem Informasi')
            ->assertSee('Belum ada data unit kerja.');
    }

    public function test_unit_kerja_ditampilkan_berjenjang_dari_database(): void
    {
        $lembaga = RefUnitKerja::create([
            'nama' => 'Kepala Lembaga Halaman',
            'jenis_unit' => 'lembaga',
            'level' => 0,
            'keterangan' => 'Unit pengarah struktur organisasi.',
        ]);
        $bagian = RefUnitKerja::create([
            'nama' => 'Bagian Halaman',
            'jenis_unit' => 'bagian',
            'parent_id' => $lembaga->id,
            'level' => 1,
        ]);
        RefUnitKerja::create([
            'nama' => 'Urusan Halaman',
            'jenis_unit' => 'urusan',
            'parent_id' => $bagian->id,
            'level' => 2,
        ]);
        $user = User::factory()->superAdmin()->create();

        $response = $this->actingAs($user)->get(route('data-master'));

        $response->assertOk()
            // Urutan depth-first: sub-unit wajib tampil tepat setelah induknya.
            ->assertSeeInOrder(['Kepala Lembaga Halaman', 'Bagian Halaman', 'Urusan Halaman'])
            ->assertSee('Unit pengarah struktur organisasi.')
            ->assertSeeHtml('name="keterangan"')
            ->assertSee('Tim Kerja');
    }

    public function test_error_edit_unit_kerja_hanya_memulihkan_form_dan_keterangan_unit_tujuan(): void
    {
        $unitA = RefUnitKerja::create([
            'nama' => 'Bagian Form A',
            'jenis_unit' => 'bagian',
            'level' => 0,
            'keterangan' => 'Keterangan tersimpan A.',
        ]);
        RefUnitKerja::create([
            'nama' => 'Bagian Form B',
            'jenis_unit' => 'bagian',
            'level' => 0,
            'keterangan' => 'Keterangan tersimpan B.',
        ]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->post(route('data-master.unit-kerja.update', $unitA), [
                '_token' => 'test-token',
                'tab' => 'unit_kerja',
                'form_context' => $unitA->id,
                'nama' => '',
                'jenis_unit' => 'bagian',
                'keterangan' => 'Draf keterangan A.',
            ], ['X-CSRF-TOKEN' => 'test-token'])
            ->assertSessionHasErrors(['nama']);

        $content = $this->get(route('data-master'))->assertOk()->getContent();

        $this->assertStringContainsString("editId: '{$unitA->id}'", $content);
        $this->assertSame(1, substr_count($content, '>Draf keterangan A.</textarea>'));
        $this->assertStringContainsString('>Keterangan tersimpan B.</textarea>', $content);
    }

    public function test_dropdown_parent_tidak_menawarkan_unit_nonaktif_baru_tetapi_mempertahankan_parent_saat_ini(): void
    {
        $parentAktif = RefUnitKerja::create([
            'nama' => 'Bagian Parent Aktif',
            'jenis_unit' => 'bagian',
            'level' => 0,
        ]);
        $parentNonaktif = RefUnitKerja::create([
            'nama' => 'Bagian Parent Nonaktif',
            'jenis_unit' => 'bagian',
            'level' => 0,
            'is_active' => false,
        ]);
        RefUnitKerja::create([
            'nama' => 'Urusan Existing Nonaktif',
            'jenis_unit' => 'urusan',
            'parent_id' => $parentNonaktif->id,
            'level' => 1,
            'is_active' => false,
        ]);
        $user = User::factory()->superAdmin()->create();

        $content = $this->actingAs($user)->get(route('data-master'))->assertOk()->getContent();

        $this->assertGreaterThan(
            1,
            preg_match_all('/<option\s+value="'.preg_quote($parentAktif->id, '/').'"/', $content),
        );
        $this->assertSame(
            1,
            preg_match_all('/<option\s+value="'.preg_quote($parentNonaktif->id, '/').'"/', $content),
        );
        $this->assertStringContainsString('Bagian Parent Nonaktif (Nonaktif)', $content);
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
