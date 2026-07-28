<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefJenisJabatan;
use App\Models\RefUnitKerja;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Menjaga CRUD unit kerja hierarkis: selain aturan CRUD referensi biasa,
 * tabel ini punya self-FK nullOnDelete sehingga penghapusan induk dapat
 * meng-orphan anaknya tanpa error database. Test di bawah mengunci guard
 * hierarki, kalkulasi level otomatis, dan pencegahan siklus.
 */
class DataMasterUnitKerjaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_dapat_menambah_unit_kerja_root_dengan_audit(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.store'), [
                'nama' => 'Kepala Lembaga Uji',
                'jenis_unit' => 'lembaga',
                'parent_id' => '',
                'keterangan' => 'Root struktur uji.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_unit_kerja', [
            'nama' => 'Kepala Lembaga Uji',
            'jenis_unit' => 'lembaga',
            'parent_id' => null,
            'level' => 0,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefUnitKerja']);
    }

    public function test_level_anak_dihitung_otomatis_dari_induk(): void
    {
        $user = User::factory()->superAdmin()->create();
        $lembaga = $this->unit('Lembaga Uji', 'lembaga');

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.store'), [
                'nama' => 'Bagian Uji',
                'jenis_unit' => 'bagian',
                'parent_id' => $lembaga->id,
            ])
            ->assertRedirect();

        $bagian = RefUnitKerja::query()->where('nama', 'Bagian Uji')->firstOrFail();
        $this->assertSame(1, $bagian->level);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.store'), [
                'nama' => 'Urusan Uji',
                'jenis_unit' => 'urusan',
                'parent_id' => $bagian->id,
            ])
            ->assertRedirect();

        $this->assertSame(2, RefUnitKerja::query()->where('nama', 'Urusan Uji')->firstOrFail()->level);
    }

    public function test_level_input_pengguna_diabaikan(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.store'), [
                'nama' => 'Unit Palsu Level',
                'jenis_unit' => 'bagian',
                'level' => 9,
            ])
            ->assertRedirect();

        // Level tidak boleh berasal dari form; tanpa induk nilainya wajib nol.
        $this->assertSame(0, RefUnitKerja::query()->where('nama', 'Unit Palsu Level')->firstOrFail()->level);
    }

    public function test_pindah_induk_menyinkronkan_level_seluruh_keturunan(): void
    {
        $user = User::factory()->superAdmin()->create();
        $lembaga = $this->unit('Lembaga Sinkron', 'lembaga');
        $bagian = $this->unit('Bagian Sinkron', 'bagian', $lembaga, 1);
        $urusan = $this->unit('Urusan Sinkron', 'urusan', $bagian, 2);
        $subUrusan = $this->unit('Sub Urusan Sinkron', 'urusan', $urusan, 3);

        // Bagian dipindah menjadi root: seluruh subtree wajib turun satu tingkat.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.update', $bagian), [
                'nama' => 'Bagian Sinkron',
                'jenis_unit' => 'bagian',
                'parent_id' => '',
            ])
            ->assertRedirect();

        $this->assertSame(0, $bagian->refresh()->level);
        $this->assertSame(1, $urusan->refresh()->level);
        $this->assertSame(2, $subUrusan->refresh()->level);
    }

    public function test_nama_unit_kerja_wajib_unik_se_sistem(): void
    {
        $user = User::factory()->superAdmin()->create();
        $lembaga = $this->unit('Lembaga Unik', 'lembaga');
        $bagianA = $this->unit('Bagian A', 'bagian', $lembaga, 1);
        $bagianB = $this->unit('Bagian B', 'bagian', $lembaga, 1);
        $this->unit('Urusan Keuangan Unik', 'urusan', $bagianA, 2);

        // Nama sama pada induk berbeda tetap ditolak: seeder dan beberapa
        // laporan mencari unit berdasarkan nama sehingga duplikat membuat
        // hasil pencarian tidak deterministik.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.store'), [
                'nama' => 'Urusan Keuangan Unik',
                'jenis_unit' => 'urusan',
                'parent_id' => $bagianB->id,
            ])
            ->assertSessionHasErrors(['nama']);

        $this->assertSame(1, RefUnitKerja::query()->where('nama', 'Urusan Keuangan Unik')->count());
    }

    public function test_jenis_unit_dibatasi_pada_kosakata_resmi(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.store'), [
                'nama' => 'Unit Jenis Ngawur',
                'jenis_unit' => 'divisi',
            ])
            ->assertSessionHasErrors(['jenis_unit']);

        $this->assertDatabaseMissing('ref_unit_kerja', ['nama' => 'Unit Jenis Ngawur']);
    }

    public function test_induk_yang_masih_punya_anak_tidak_dapat_dihapus(): void
    {
        $user = User::factory()->superAdmin()->create();
        $lembaga = $this->unit('Lembaga Berandak', 'lembaga');
        $anak = $this->unit('Bagian Anak', 'bagian', $lembaga, 1);

        // FK parent_id bersifat nullOnDelete: tanpa guard ini database akan
        // menerima penghapusan dan membuat anaknya menjadi root diam-diam.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.destroy', $lembaga), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_unit_kerja', ['id' => $lembaga->id]);
        $this->assertSame($lembaga->id, $anak->refresh()->parent_id);
    }

    public function test_unit_yang_dipakai_riwayat_jabatan_tidak_dapat_dihapus(): void
    {
        $user = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Terpakai', 'bagian');
        $employee = Employee::factory()->create();
        $employee->positionHistories()->create([
            'nama_jabatan' => 'Kepala Bagian Terpakai',
            'jenis_jabatan_id' => RefJenisJabatan::create(['nama' => 'Struktural Uji', 'maks_usia_pensiun' => 58])->id,
            'unit_kerja_id' => $unit->id,
            'tmt_jabatan' => '2020-01-01',
            'no_sk' => 'SK-UNIT-001',
            'tanggal_sk' => '2019-12-20',
            'is_latest' => true,
        ]);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.destroy', $unit), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_unit_kerja', ['id' => $unit->id]);
    }

    public function test_unit_daun_tanpa_pemakai_dapat_dihapus_permanen_dengan_audit(): void
    {
        $user = User::factory()->superAdmin()->create();
        $unit = $this->unit('Urusan Sepi', 'urusan');

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.destroy', $unit), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_unit_kerja', ['id' => $unit->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefUnitKerja',
            'auditable_id' => $unit->id,
        ]);
    }

    public function test_induk_tidak_boleh_menjadi_dirinya_sendiri(): void
    {
        $user = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Narsis', 'bagian');

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.update', $unit), [
                'nama' => 'Bagian Narsis',
                'jenis_unit' => 'bagian',
                'parent_id' => $unit->id,
            ])
            ->assertSessionHasErrors(['parent_id']);

        $this->assertNull($unit->refresh()->parent_id);
    }

    public function test_induk_tidak_boleh_diambil_dari_keturunannya(): void
    {
        $user = User::factory()->superAdmin()->create();
        $lembaga = $this->unit('Lembaga Siklus', 'lembaga');
        $bagian = $this->unit('Bagian Siklus', 'bagian', $lembaga, 1);
        $urusan = $this->unit('Urusan Siklus', 'urusan', $bagian, 2);

        // Menjadikan cucu sebagai induk kakeknya membuat siklus tak berujung
        // saat level dan pohon dihitung.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.update', $lembaga), [
                'nama' => 'Lembaga Siklus',
                'jenis_unit' => 'lembaga',
                'parent_id' => $urusan->id,
            ])
            ->assertSessionHasErrors(['parent_id']);

        $this->assertNull($lembaga->refresh()->parent_id);
    }

    public function test_induk_dengan_anak_aktif_tidak_dapat_dinonaktifkan(): void
    {
        $user = User::factory()->superAdmin()->create();
        $lembaga = $this->unit('Lembaga Aktif', 'lembaga');
        $this->unit('Bagian Masih Aktif', 'bagian', $lembaga, 1);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.toggle', $lembaga), [])
            ->assertSessionHasErrors();

        $this->assertTrue($lembaga->refresh()->is_active);
        // Guard wajib berjalan sebelum mutasi sehingga tidak ada jejak audit palsu.
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefUnitKerja',
            'auditable_id' => $lembaga->id,
        ]);
    }

    public function test_parent_id_rusak_ditolak_validasi_bukan_error_database(): void
    {
        $user = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Uji UUID', 'bagian');

        // PostgreSQL menolak sintaks uuid yang rusak dengan error 500 bila
        // nilainya sampai ke query, jadi validasi harus menahannya lebih dulu.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.update', $unit), [
                'nama' => 'Bagian Uji UUID',
                'jenis_unit' => 'bagian',
                'parent_id' => 'bukan-uuid',
            ])
            ->assertSessionHasErrors(['parent_id']);

        $this->assertNull($unit->refresh()->parent_id);
    }

    public function test_rantai_induk_yang_melingkar_ditolak_saat_dijadikan_induk(): void
    {
        $user = User::factory()->superAdmin()->create();
        $polos = $this->unit('Bagian Polos', 'bagian');
        $a = $this->unit('Bagian Lingkar A', 'bagian');
        $b = $this->unit('Bagian Lingkar B', 'bagian', $a, 1);
        // Data lama yang sudah rusak: A dan B saling menjadi induk.
        $a->forceFill(['parent_id' => $b->id])->save();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.update', $polos), [
                'nama' => 'Bagian Polos',
                'jenis_unit' => 'bagian',
                'parent_id' => $b->id,
            ])
            ->assertSessionHasErrors(['parent_id']);

        $this->assertNull($polos->refresh()->parent_id);
    }

    public function test_setiap_mutasi_menghapus_cache_dropdown(): void
    {
        $user = User::factory()->superAdmin()->create();
        $unit = $this->unit('Bagian Cache', 'bagian');

        foreach ([
            ['route' => route('data-master.unit-kerja.update', $unit), 'data' => ['nama' => 'Bagian Cache Baru', 'jenis_unit' => 'bagian']],
            ['route' => route('data-master.unit-kerja.toggle', $unit), 'data' => []],
            ['route' => route('data-master.unit-kerja.destroy', $unit), 'data' => []],
        ] as $mutasi) {
            Cache::put('ref.unit_kerja', ['stale'], 3600);

            $this->actingAs($user)->postWithCsrf($mutasi['route'], $mutasi['data'])->assertRedirect();

            $this->assertNull(Cache::get('ref.unit_kerja'));
        }
    }

    public function test_induk_dapat_dinonaktifkan_setelah_anaknya_nonaktif(): void
    {
        $user = User::factory()->superAdmin()->create();
        $lembaga = $this->unit('Lembaga Tutup', 'lembaga');
        $anak = $this->unit('Bagian Tutup', 'bagian', $lembaga, 1);
        $anak->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.toggle', $lembaga), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($lembaga->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefUnitKerja',
            'auditable_id' => $lembaga->id,
        ]);
    }

    public function test_unit_daun_nonaktif_dapat_diaktifkan_kembali(): void
    {
        $user = User::factory()->superAdmin()->create();
        $unit = $this->unit('Urusan Bangkit', 'urusan');
        $unit->forceFill(['is_active' => false])->save();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.toggle', $unit), [])
            ->assertRedirect();

        $this->assertTrue($unit->refresh()->is_active);
    }

    public function test_mutasi_unit_kerja_menghapus_cache_dropdown(): void
    {
        Cache::put('ref.unit_kerja', ['stale'], 3600);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.store'), [
                'nama' => 'Bagian Segar',
                'jenis_unit' => 'bagian',
            ])
            ->assertRedirect();

        $this->assertNull(Cache::get('ref.unit_kerja'));
    }

    public function test_admin_kepegawaian_tidak_boleh_mengelola_unit_kerja(): void
    {
        $unit = $this->unit('Bagian Terlarang', 'bagian');
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.store'), ['nama' => 'Bagian Selundupan', 'jenis_unit' => 'bagian'])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.unit-kerja.destroy', $unit), [])
            ->assertForbidden();

        $this->assertSame(1, RefUnitKerja::query()->count());
    }

    private function unit(string $nama, string $jenis, ?RefUnitKerja $parent = null, int $level = 0): RefUnitKerja
    {
        return RefUnitKerja::create([
            'nama' => $nama,
            'jenis_unit' => $jenis,
            'parent_id' => $parent?->id,
            'level' => $level,
            'is_active' => true,
        ]);
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
