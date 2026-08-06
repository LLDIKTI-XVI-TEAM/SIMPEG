<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefEselon;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DataMasterJabatanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_dapat_menambah_jabatan_dengan_audit(): void
    {
        $jenis = RefJenisJabatan::create(['nama' => 'Fungsional Tertentu', 'maks_usia_pensiun' => 58]);
        $eselon = RefEselon::create(['kode' => 'IV.a', 'nama' => 'Eselon IV.a']);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.store'), [
                'nama' => 'Analis Kepegawaian',
                'jenis_jabatan_id' => $jenis->id,
                'eselon_id' => $eselon->id,
                'default_bup' => 58,
                'keterangan' => 'Jabatan fungsional bidang kepegawaian.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_jabatan', [
            'nama' => 'Analis Kepegawaian',
            'jenis_jabatan_id' => $jenis->id,
            'eselon_id' => $eselon->id,
            'default_bup' => 58,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefJabatan']);
    }

    public function test_tambah_jabatan_menolak_nama_duplikat(): void
    {
        RefJabatan::create(['nama' => 'Analis Kepegawaian']);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.store'), ['nama' => 'Analis Kepegawaian'])
            ->assertSessionHasErrors(['nama']);

        $this->assertSame(1, RefJabatan::query()->count());
    }

    public function test_super_admin_dapat_mengubah_jabatan_termasuk_default_bup(): void
    {
        $jabatan = RefJabatan::create(['nama' => 'Kepala Bagian Umum', 'default_bup' => 58]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.update', $jabatan), [
                'nama' => 'Kepala Bagian Umum',
                'default_bup' => 60,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_jabatan', ['id' => $jabatan->id, 'default_bup' => 60]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefJabatan',
            'auditable_id' => $jabatan->id,
        ]);
    }

    public function test_default_bup_di_luar_rentang_ditolak(): void
    {
        $user = User::factory()->superAdmin()->create();

        // Angka jauh di bawah batas menghasilkan tanggal pensiun di masa lalu sehingga
        // seluruh pemegang jabatan langsung tampak melewati BUP; angka di atas batas
        // tidak dikenal aturan kepegawaian mana pun.
        foreach ([20, 90] as $angkaTidakWajar) {
            $this->actingAs($user)
                ->postWithCsrf(route('data-master.jabatan.store'), [
                    'nama' => 'Jabatan Uji '.$angkaTidakWajar,
                    'default_bup' => $angkaTidakWajar,
                ])
                ->assertSessionHasErrors('default_bup');

            $this->assertDatabaseMissing('ref_jabatan', ['nama' => 'Jabatan Uji '.$angkaTidakWajar]);
        }
    }

    public function test_default_bup_boleh_kosong(): void
    {
        $user = User::factory()->superAdmin()->create();

        // Jabatan tanpa BUP khusus mengikuti maks_usia_pensiun pada jenis jabatannya,
        // sehingga nilai kosong adalah pilihan sah, bukan data yang belum lengkap.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.store'), [
                'nama' => 'Pengelola Barang Milik Negara',
                'default_bup' => '',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_jabatan', [
            'nama' => 'Pengelola Barang Milik Negara',
            'default_bup' => null,
        ]);
    }

    public function test_toggle_jabatan_mencatat_audit_config_update(): void
    {
        $jabatan = RefJabatan::create(['nama' => 'Jabatan Nonaktif Uji']);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.toggle', $jabatan), [])
            ->assertRedirect();

        $this->assertFalse($jabatan->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefJabatan',
            'auditable_id' => $jabatan->id,
        ]);
    }

    public function test_jabatan_yang_belum_dipakai_dapat_dihapus_permanen(): void
    {
        $jabatan = RefJabatan::create(['nama' => 'Jabatan Belum Terpakai']);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.destroy', $jabatan), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_jabatan', ['id' => $jabatan->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefJabatan',
            'auditable_id' => $jabatan->id,
        ]);
    }

    public function test_jabatan_yang_dipakai_riwayat_jabatan_tidak_dapat_dihapus(): void
    {
        $jabatan = RefJabatan::create(['nama' => 'Analis Kepegawaian', 'is_active' => true]);
        $employee = Employee::factory()->create();
        // FK position_histories.jabatan_id bersifat nullOnDelete sehingga database
        // tidak memblokir penghapusan dan justru mengosongkan kolom riwayat diam-diam;
        // guard aplikasi adalah satu-satunya pelindung jejak jabatan pegawai.
        $employee->positionHistories()->create([
            'jabatan_id' => $jabatan->id,
            'nama_jabatan' => 'Analis Kepegawaian',
            'tmt_jabatan' => '2026-01-01',
        ]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.destroy', $jabatan), [])
            ->assertSessionHasErrors('referensi');

        $this->assertDatabaseHas('ref_jabatan', ['id' => $jabatan->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefJabatan',
        ]);
    }

    public function test_mutasi_jabatan_menghapus_cache_dropdown(): void
    {
        // Cache menyimpan snapshot jabatan beserta jenisnya untuk dropdown penugasan,
        // sehingga perubahan referensi harus membuangnya agar pilihan tidak basi.
        Cache::put('ref.jabatan_with_jenis', ['stale'], 3600);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.store'), ['nama' => 'Pranata Komputer'])
            ->assertRedirect();

        $this->assertNull(Cache::get('ref.jabatan_with_jenis'));
    }

    public function test_admin_kepegawaian_tidak_boleh_mengelola_jabatan(): void
    {
        $jabatan = RefJabatan::create(['nama' => 'Analis Kepegawaian']);
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.store'), ['nama' => 'Jabatan Baru'])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.update', $jabatan), ['nama' => 'Diubah Paksa'])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.toggle', $jabatan), [])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.destroy', $jabatan), [])
            ->assertForbidden();

        $this->assertSame(1, RefJabatan::query()->count());
        $this->assertTrue($jabatan->refresh()->is_active);
        $this->assertSame('Analis Kepegawaian', $jabatan->nama);
    }

    public function test_jabatan_nonaktif_ditolak_pada_riwayat_jabatan_baru(): void
    {
        // Penyaringan dropdown di tampilan tidak cukup: penolakan harus ditegakkan server
        // agar permintaan langsung tidak bisa menautkan jabatan yang sudah dinonaktifkan.
        $jabatanNonaktif = RefJabatan::create(['nama' => 'Jabatan Dibekukan', 'is_active' => false]);
        $unit = RefUnitKerja::create(['nama' => 'Bagian Umum Uji', 'jenis' => 'bagian']);
        $employee = Employee::factory()->create();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-jabatan", [
                'jabatan_id' => $jabatanNonaktif->id,
                'nama_jabatan' => 'Jabatan Dibekukan',
                'unit_kerja_id' => $unit->id,
                'tmt_jabatan' => '2026-02-01',
                'no_sk' => 'SK-2026-010',
                'tanggal_sk' => '2026-01-20',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('jabatan_id');

        $this->assertDatabaseMissing('position_histories', ['jabatan_id' => $jabatanNonaktif->id]);
    }

    public function test_jabatan_aktif_tetap_diterima_pada_riwayat_jabatan_baru(): void
    {
        $jabatanAktif = RefJabatan::create(['nama' => 'Analis Kepegawaian Aktif', 'is_active' => true]);
        $unit = RefUnitKerja::create(['nama' => 'Bagian Umum Uji', 'jenis' => 'bagian']);
        $employee = Employee::factory()->create();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postJsonWithCsrf("/api/v1/pegawai/{$employee->id}/riwayat-jabatan", [
                'jabatan_id' => $jabatanAktif->id,
                'nama_jabatan' => 'Analis Kepegawaian Aktif',
                'unit_kerja_id' => $unit->id,
                'tmt_jabatan' => '2026-02-01',
                'no_sk' => 'SK-2026-011',
                'tanggal_sk' => '2026-01-20',
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('position_histories', [
            'employee_id' => $employee->id,
            'jabatan_id' => $jabatanAktif->id,
        ]);
    }

    public function test_pembuatan_pegawai_menolak_jabatan_nonaktif(): void
    {
        // Jalur formulir pegawai juga menulis riwayat jabatan, sehingga penolakan harus sama
        // dengan endpoint riwayat agar jabatan nonaktif tidak masuk lewat pintu lain.
        $jabatanNonaktif = RefJabatan::create(['nama' => 'Jabatan Dibekukan', 'is_active' => false]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('pegawai.store'), ['jabatan_jabatan_id' => $jabatanNonaktif->id])
            ->assertSessionHasErrors('jabatan_jabatan_id');
    }

    public function test_pembaruan_pegawai_boleh_mempertahankan_jabatan_nonaktif_yang_sudah_tercatat(): void
    {
        $jabatanNonaktif = RefJabatan::create(['nama' => 'Jabatan Dibekukan', 'is_active' => false]);
        $employee = Employee::factory()->create();
        $employee->positionHistories()->create([
            'jabatan_id' => $jabatanNonaktif->id,
            'nama_jabatan' => 'Jabatan Dibekukan',
            'tmt_jabatan' => '2026-01-01',
        ]);
        $user = User::factory()->superAdmin()->create();

        // Penugasan yang sudah tercatat tetap boleh dipertahankan agar admin dapat mengoreksi
        // metadata lain tanpa dipaksa mengganti jabatan pegawai.
        $this->actingAs($user)
            ->postWithCsrf(route('pegawai.update', $employee->id), ['jabatan_jabatan_id' => $jabatanNonaktif->id])
            ->assertSessionDoesntHaveErrors('jabatan_jabatan_id');
    }

    public function test_jenis_jabatan_dan_eselon_nonaktif_ditolak_saat_membuat_jabatan(): void
    {
        $jenisNonaktif = RefJenisJabatan::create(['nama' => 'Jenis Dibekukan', 'maks_usia_pensiun' => 58, 'is_active' => false]);
        $eselonNonaktif = RefEselon::create(['kode' => 'V.z', 'nama' => 'Eselon Dibekukan', 'is_active' => false]);
        $user = User::factory()->superAdmin()->create();

        // Jenis jabatan menjadi sumber cadangan batas usia pensiun, sehingga jabatan baru tidak
        // boleh menautkan referensi yang sudah dinonaktifkan meski formulir menyembunyikannya.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.store'), [
                'nama' => 'Jabatan Relasi Nonaktif',
                'jenis_jabatan_id' => $jenisNonaktif->id,
            ])
            ->assertSessionHasErrors('jenis_jabatan_id');

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.store'), [
                'nama' => 'Jabatan Eselon Nonaktif',
                'eselon_id' => $eselonNonaktif->id,
            ])
            ->assertSessionHasErrors('eselon_id');

        $this->assertSame(0, RefJabatan::query()->count());
    }

    public function test_ubah_jabatan_boleh_mempertahankan_jenis_jabatan_nonaktif_yang_sudah_terpasang(): void
    {
        $jenisNonaktif = RefJenisJabatan::create(['nama' => 'Jenis Dibekukan', 'maks_usia_pensiun' => 58, 'is_active' => false]);
        $jabatan = RefJabatan::create(['nama' => 'Analis Warisan', 'jenis_jabatan_id' => $jenisNonaktif->id]);
        $user = User::factory()->superAdmin()->create();

        // Admin harus tetap dapat mengoreksi kolom lain tanpa dipaksa mengganti relasi lama.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jabatan.update', $jabatan), [
                'nama' => 'Analis Warisan',
                'jenis_jabatan_id' => $jenisNonaktif->id,
                'default_bup' => 60,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_jabatan', ['id' => $jabatan->id, 'default_bup' => 60]);
    }

    public function test_uuid_tidak_valid_menghasilkan_not_found(): void
    {
        $user = User::factory()->superAdmin()->create();

        // Constraint whereUuid pada route menjaga parameter cacat berhenti sebagai 404,
        // bukan menjadi galat query PostgreSQL.
        $this->actingAs($user)
            ->postWithCsrf('/data-master/jabatan/bukan-uuid/update', ['nama' => 'Apa Saja'])
            ->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function postJsonWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
