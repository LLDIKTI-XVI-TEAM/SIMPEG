<?php

namespace Tests\Feature;

use App\Models\RefHariLibur;
use App\Models\User;
use App\Services\WorkdayCalculator;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class HariLiburWebPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nama ini hanya pernah ada pada tabel statis versi lama halaman Hari Libur.
     * Dipakai sebagai penanda regresi: bila muncul lagi, berarti halaman kembali
     * merender data hardcoded, bukan isi ref_hari_libur.
     */
    private const NAMA_DATA_STATIS_LAMA = 'Hari Suci Nyepi Saka 1948';

    protected function setUp(): void
    {
        parent::setUp();

        // Permission hari_libur.* dibutuhkan middleware permission pada route web.
        $this->seed(RbacSeeder::class);
    }

    public function test_guest_tidak_dapat_membuka_halaman_hari_libur(): void
    {
        $this->get(route('hari-libur'))->assertRedirect('/login');
    }

    public function test_admin_kepegawaian_tidak_dapat_membuka_halaman_hari_libur(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->get(route('hari-libur'))
            ->assertForbidden();
    }

    public function test_pegawai_tidak_dapat_membuka_halaman_hari_libur(): void
    {
        $this->actingAs(User::factory()->pegawai()->create())
            ->get(route('hari-libur'))
            ->assertForbidden();
    }

    public function test_halaman_menampilkan_hari_libur_dari_database_bukan_data_statis(): void
    {
        $this->buatHariLibur('2026-01-01', 'Tahun Baru Masehi');
        $this->buatHariLibur('2026-03-20', 'Cuti Bersama Idul Fitri', true);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('hari-libur', ['tahun' => 2026]));

        $response->assertOk();
        $response->assertSee('Tahun Baru Masehi');
        $response->assertSee('Cuti Bersama Idul Fitri');
        $response->assertSee('Libur Nasional');
        $response->assertSee('Cuti Bersama');
        $response->assertDontSee(self::NAMA_DATA_STATIS_LAMA);
    }

    public function test_halaman_menampilkan_empty_state_saat_tabel_referensi_kosong(): void
    {
        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('hari-libur'));

        $response->assertOk();
        $response->assertSee('Belum ada hari libur untuk filter yang dipilih.');
        $response->assertDontSee(self::NAMA_DATA_STATIS_LAMA);
    }

    public function test_filter_tahun_membatasi_baris_yang_tampil(): void
    {
        $this->buatHariLibur('2026-01-01', 'Tahun Baru 2026');
        $this->buatHariLibur('2025-01-01', 'Tahun Baru 2025');

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('hari-libur', ['tahun' => 2025]));

        $response->assertOk();
        $response->assertSee('Tahun Baru 2025');
        $response->assertDontSee('Tahun Baru 2026');
    }

    public function test_filter_tipe_membatasi_baris_yang_tampil(): void
    {
        $this->buatHariLibur('2026-01-01', 'Tahun Baru Masehi');
        $this->buatHariLibur('2026-03-20', 'Cuti Bersama Idul Fitri', true);

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('hari-libur', ['tahun' => 2026, 'tipe' => 'cuti_bersama']));

        $response->assertOk();
        $response->assertSee('Cuti Bersama Idul Fitri');
        $response->assertDontSee('Tahun Baru Masehi');
    }

    public function test_pencarian_nama_berjalan_di_server_dan_tidak_peka_huruf(): void
    {
        $this->buatHariLibur('2026-01-01', 'Tahun Baru Masehi');
        $this->buatHariLibur('2026-12-25', 'Hari Raya Natal');

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('hari-libur', ['tahun' => 2026, 'search' => 'natal']));

        $response->assertOk();
        $response->assertSee('Hari Raya Natal');
        $response->assertDontSee('Tahun Baru Masehi');
    }

    public function test_paginasi_dijalankan_di_server(): void
    {
        for ($hari = 1; $hari <= 12; $hari++) {
            $this->buatHariLibur(sprintf('2026-01-%02d', $hari), 'Libur Uji '.$hari);
        }

        $user = User::factory()->superAdmin()->create();

        $halamanPertama = $this->actingAs($user)
            ->get(route('hari-libur', ['tahun' => 2026, 'per_page' => 10]));

        $halamanPertama->assertOk();
        $halamanPertama->assertSee('Libur Uji 1');
        $halamanPertama->assertDontSee('Libur Uji 11');
        $halamanPertama->assertSee('dari');

        $halamanKedua = $this->actingAs($user)
            ->get(route('hari-libur', ['tahun' => 2026, 'per_page' => 10, 'page' => 2]));

        $halamanKedua->assertOk();
        $halamanKedua->assertSee('Libur Uji 11');
        $halamanKedua->assertSee('Libur Uji 12');
        $halamanKedua->assertDontSee('Libur Uji 1<');
    }

    public function test_per_page_di_luar_allowlist_ditolak(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('hari-libur', ['per_page' => 1000]))
            ->assertSessionHasErrors(['per_page']);
    }

    public function test_tipe_filter_tidak_valid_ditolak(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('hari-libur', ['tipe' => 'libur_daerah']))
            ->assertSessionHasErrors(['tipe']);
    }

    public function test_super_admin_dapat_menambah_hari_libur_dari_halaman_web_dengan_audit(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('hari-libur.store'), [
                'tanggal' => '2026-03-20',
                'nama' => 'Cuti Bersama Idul Fitri',
                'tipe' => 'cuti_bersama',
            ])
            ->assertRedirect(route('hari-libur', ['tahun' => 2026]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_hari_libur', [
            'nama' => 'Cuti Bersama Idul Fitri',
            'tahun' => 2026,
            'is_cuti_bersama' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'RefHariLibur',
        ]);
    }

    public function test_mutasi_web_tidak_lagi_menulis_audit_palsu_di_session(): void
    {
        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('hari-libur.store'), [
                'tanggal' => '2026-01-01',
                'nama' => 'Tahun Baru Masehi',
                'tipe' => 'libur_nasional',
            ]);

        $response->assertRedirect();
        // Versi lama menyimpan jejak perubahan di session dengan event buatan
        // sendiri sehingga tidak pernah masuk audit_logs dan hilang saat logout.
        $response->assertSessionMissing('dynamic_audit_logs');
        $this->assertDatabaseMissing('audit_logs', ['event' => 'CREATE_HOLIDAY']);
    }

    public function test_tambah_dari_web_menolak_tanggal_duplikat(): void
    {
        $this->buatHariLibur('2026-01-01', 'Tahun Baru Masehi');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('hari-libur.store'), [
                'tanggal' => '2026-01-01',
                'nama' => 'Tahun Baru Duplikat',
                'tipe' => 'libur_nasional',
            ])
            ->assertSessionHasErrors(['tanggal']);

        $this->assertSame(1, RefHariLibur::query()->count());
    }

    public function test_tambah_dari_web_menolak_input_tidak_lengkap(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('hari-libur.store'), [
                'tanggal' => '01/01/2026',
                'nama' => '',
                'tipe' => 'libur_daerah',
            ])
            ->assertSessionHasErrors(['tanggal', 'nama', 'tipe']);

        $this->assertSame(0, RefHariLibur::query()->count());
    }

    public function test_admin_kepegawaian_tidak_dapat_menambah_hari_libur_dari_web(): void
    {
        $this->actingAs(User::factory()->adminKepegawaian()->create())
            ->postWithCsrf(route('hari-libur.store'), [
                'tanggal' => '2026-01-01',
                'nama' => 'Tahun Baru Masehi',
                'tipe' => 'libur_nasional',
            ])
            ->assertForbidden();

        $this->assertSame(0, RefHariLibur::query()->count());
    }

    public function test_halaman_edit_menampilkan_data_dari_database(): void
    {
        $hariLibur = $this->buatHariLibur('2026-12-25', 'Hari Raya Natal');

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('hari-libur.edit', $hariLibur));

        $response->assertOk();
        $response->assertSee('Hari Raya Natal');
        $response->assertSee('2026-12-25');
    }

    public function test_halaman_edit_menolak_uuid_tidak_valid(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/hari-libur/bukan-uuid/edit')
            ->assertNotFound();
    }

    public function test_super_admin_dapat_mengubah_hari_libur_dari_halaman_web_dengan_audit(): void
    {
        $hariLibur = $this->buatHariLibur('2026-01-01', 'Tahun Baru Masehi');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putWithCsrf(route('hari-libur.update', $hariLibur), [
                'tanggal' => '2026-01-02',
                'nama' => 'Cuti Bersama Tahun Baru',
                'tipe' => 'cuti_bersama',
            ])
            ->assertRedirect(route('hari-libur', ['tahun' => 2026]))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_hari_libur', [
            'id' => $hariLibur->id,
            'nama' => 'Cuti Bersama Tahun Baru',
            'is_cuti_bersama' => true,
        ]);
        $this->assertSame('2026-01-02', $hariLibur->refresh()->tanggal->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefHariLibur',
            'auditable_id' => $hariLibur->id,
        ]);
    }

    public function test_ubah_dari_web_menolak_tanggal_milik_baris_lain(): void
    {
        $this->buatHariLibur('2026-01-01', 'Tahun Baru Masehi');
        $hariLibur = $this->buatHariLibur('2026-03-20', 'Cuti Bersama Idul Fitri', true);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->putWithCsrf(route('hari-libur.update', $hariLibur), [
                'tanggal' => '2026-01-01',
                'nama' => 'Duplikat Tanggal',
                'tipe' => 'libur_nasional',
            ])
            ->assertSessionHasErrors(['tanggal']);

        $this->assertSame('2026-03-20', $hariLibur->refresh()->tanggal->format('Y-m-d'));
    }

    public function test_super_admin_dapat_menghapus_hari_libur_dari_halaman_web_dengan_audit(): void
    {
        $hariLibur = $this->buatHariLibur('2026-12-25', 'Hari Raya Natal');

        $this->actingAs(User::factory()->superAdmin()->create())
            ->deleteWithCsrf(route('hari-libur.destroy', $hariLibur))
            ->assertRedirect(route('hari-libur', ['tahun' => 2026]))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_hari_libur', ['id' => $hariLibur->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefHariLibur',
            'auditable_id' => $hariLibur->id,
        ]);
    }

    public function test_pegawai_tidak_dapat_menghapus_hari_libur_dari_web(): void
    {
        $hariLibur = $this->buatHariLibur('2026-12-25', 'Hari Raya Natal');

        $this->actingAs(User::factory()->pegawai()->create())
            ->deleteWithCsrf(route('hari-libur.destroy', $hariLibur))
            ->assertForbidden();

        $this->assertDatabaseHas('ref_hari_libur', ['id' => $hariLibur->id]);
    }

    /**
     * Mengunci alasan utama perbaikan ini: hari libur yang diinput dari halaman
     * web harus benar-benar mengurangi hari kerja pengajuan cuti. Sebelumnya
     * halaman melaporkan sukses tanpa menyentuh ref_hari_libur sehingga
     * kalkulasi tetap menghitung tanggal tersebut sebagai hari kerja.
     */
    public function test_hari_libur_dari_web_mengurangi_kalkulasi_hari_kerja_cuti(): void
    {
        $calculator = app(WorkdayCalculator::class);
        $mulai = Carbon::parse('2026-01-05'); // Senin
        $selesai = Carbon::parse('2026-01-09'); // Jumat

        $this->assertSame(5, $calculator->calculate($mulai, $selesai));

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('hari-libur.store'), [
                'tanggal' => '2026-01-07',
                'nama' => 'Libur Uji Kalkulasi',
                'tipe' => 'libur_nasional',
            ])
            ->assertRedirect();

        $this->assertSame(4, $calculator->calculate($mulai, $selesai));
    }

    private function buatHariLibur(string $tanggal, string $nama, bool $cutiBersama = false): RefHariLibur
    {
        return RefHariLibur::create([
            'tanggal' => $tanggal,
            'nama' => $nama,
            'tahun' => (int) Carbon::parse($tanggal)->format('Y'),
            'is_cuti_bersama' => $cutiBersama,
        ]);
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function putWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->put($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function deleteWithCsrf(string $uri): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->delete($uri, [], ['X-CSRF-TOKEN' => 'test-token']);
    }
}
