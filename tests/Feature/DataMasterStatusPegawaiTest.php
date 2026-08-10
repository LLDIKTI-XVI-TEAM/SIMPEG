<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\RefStatusPegawai;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Catatan konteks data: baris ref_status_pegawai (AKTIF, PENSIUN, dst.)
 * sudah ditanam oleh migration 2026_07_20 saat migrate, jadi test di kelas
 * ini memakai baris bawaan tersebut dan hanya menambah kode baru yang unik.
 */
class DataMasterStatusPegawaiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_dapat_menambah_status_dengan_audit(): void
    {
        $user = User::factory()->superAdmin()->create();

        // is_default sengaja dikirim untuk membuktikan field itu TIDAK bisa
        // di-mass-assign dari form; status default hanya boleh berubah lewat
        // keputusan terpisah, bukan input CRUD biasa.
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'UJI_COBA',
                'nama' => 'Uji Coba',
                'kelompok' => 'Nonaktif',
                'keterangan' => 'Status percobaan.',
                'is_default' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_status_pegawai', [
            'kode' => 'UJI_COBA',
            'nama' => 'Uji Coba',
            'kelompok' => 'Nonaktif',
            'is_default' => false,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefStatusPegawai']);
    }

    public function test_tambah_status_menolak_kode_dan_nama_duplikat(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'AKTIF',
                'nama' => 'Status Baru',
                'kelompok' => 'Aktif',
            ])
            ->assertSessionHasErrors(['kode']);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'STATUS_BARU',
                'nama' => 'Aktif',
                'kelompok' => 'Aktif',
            ])
            ->assertSessionHasErrors(['nama']);
    }

    public function test_super_admin_dapat_mengubah_status_biasa_dengan_audit(): void
    {
        $status = RefStatusPegawai::query()->where('kode', 'TUGAS_BELAJAR')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $status), [
                'kode' => 'TUGAS_BELAJAR',
                'nama' => 'Tugas Belajar',
                'kelompok' => 'Aktif/khusus',
                'keterangan' => 'Pegawai sedang menempuh tugas belajar resmi.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_status_pegawai', [
            'id' => $status->id,
            'keterangan' => 'Pegawai sedang menempuh tugas belajar resmi.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefStatusPegawai',
            'auditable_id' => $status->id,
        ]);
    }

    public function test_kode_dan_nama_status_sistem_tidak_dapat_diubah(): void
    {
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $pensiun = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $aktif), [
                'kode' => 'AKTIF',
                'nama' => 'Aktif Penuh',
                'kelompok' => 'Aktif',
            ])
            ->assertSessionHasErrors(['nama']);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $pensiun), [
                'kode' => 'PENSIUN_BARU',
                'nama' => 'Pensiun',
                'kelompok' => 'Nonaktif',
            ])
            ->assertSessionHasErrors(['kode']);

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $aktif->id, 'nama' => 'Aktif']);
        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $pensiun->id, 'kode' => 'PENSIUN']);
    }

    public function test_keterangan_status_sistem_masih_boleh_diubah(): void
    {
        $aktif = RefStatusPegawai::query()->where('kode', 'AKTIF')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.update', $aktif), [
                'kode' => 'AKTIF',
                'nama' => 'Aktif',
                'kelompok' => 'Aktif',
                'keterangan' => 'Pegawai aktif bekerja penuh.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_status_pegawai', [
            'id' => $aktif->id,
            'keterangan' => 'Pegawai aktif bekerja penuh.',
        ]);
    }

    public function test_toggle_status_biasa_dengan_audit_config_update(): void
    {
        $status = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.toggle', $status), [])
            ->assertRedirect();

        $this->assertFalse($status->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefStatusPegawai',
            'auditable_id' => $status->id,
        ]);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.toggle', $status), [])
            ->assertRedirect();

        $this->assertTrue($status->refresh()->is_active);
    }

    public function test_status_sistem_tidak_dapat_dinonaktifkan(): void
    {
        // AKTIF (sekaligus is_default) dan PENSIUN dipakai langsung oleh
        // logika sistem: scheduler EWS memfilter pegawai 'Aktif', proses
        // followup pensiun mencari kode 'PENSIUN', dan pegawai baru/import
        // memakai baris default. Menonaktifkan keduanya mematikan alur itu.
        $user = User::factory()->superAdmin()->create();

        foreach (['AKTIF', 'PENSIUN'] as $kode) {
            $status = RefStatusPegawai::query()->where('kode', $kode)->firstOrFail();

            $this->actingAs($user)
                ->postWithCsrf(route('data-master.status-pegawai.toggle', $status), [])
                ->assertSessionHasErrors();

            $this->assertTrue($status->refresh()->is_active);
        }
    }

    public function test_status_sistem_tidak_dapat_dihapus_meski_belum_dipakai(): void
    {
        // Guard kode sistem melengkapi K-1: cek pemakaian saja tidak cukup
        // karena PENSIUN bisa saja belum dipakai pegawai mana pun padahal
        // proses followup EWS mencarinya dengan firstOrFail (crash bila hilang).
        $pensiun = RefStatusPegawai::query()->where('kode', 'PENSIUN')->firstOrFail();
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $pensiun), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $pensiun->id]);
    }

    public function test_status_yang_dipakai_pegawai_tidak_dapat_dihapus(): void
    {
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_DIPAKAI',
            'nama' => 'Uji Dipakai',
            'kelompok' => 'Nonaktif',
        ]);
        // FK employees.status_pegawai_id bersifat nullOnDelete sehingga
        // database tidak memblokir penghapusan; guard aplikasi satu-satunya
        // pelindung agar status pegawai tidak hilang diam-diam. Tautan FK
        // dibuat lewat query builder karena simpan via model ikut menyalin
        // nama status ke kolom legacy status_aktif yang masih dibatasi
        // CHECK constraint enum lama.
        $employee = Employee::factory()->create();
        DB::table('employees')->where('id', $employee->id)->update(['status_pegawai_id' => $status->id]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $status->id]);
    }

    public function test_status_yang_dipakai_riwayat_status_tidak_dapat_dihapus(): void
    {
        // Riwayat status bersifat append-only: pegawai yang sudah berpindah
        // status meninggalkan baris lama sebagai satu-satunya perujuk status
        // tersebut. Kolom status terkini pegawai sengaja dibiarkan menunjuk
        // status lain supaya penolakan benar-benar berasal dari pemeriksaan
        // riwayat, bukan dari pemeriksaan data pegawai yang sudah ada.
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_RIWAYAT',
            'nama' => 'Uji Riwayat',
            'kelompok' => 'Nonaktif',
        ]);
        $employee = Employee::factory()->create();
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'status_nama' => 'Uji Riwayat',
            'tanggal_efektif' => '2026-01-01',
            'is_latest' => false,
        ]);

        $this->assertNotSame($status->id, $employee->refresh()->status_pegawai_id);

        $this->actingAs(User::factory()->superAdmin()->create())
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $status->id]);
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
        ]);
    }

    public function test_fk_status_pegawai_menolak_penghapusan_langsung_saat_dipakai_riwayat(): void
    {
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_FK_RIWAYAT',
            'nama' => 'Uji FK Riwayat',
            'kelompok' => 'Nonaktif',
        ]);
        $employee = Employee::factory()->create();
        EmployeeStatusHistory::create([
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
            'status_nama' => 'Uji FK Riwayat',
            'tanggal_efektif' => '2026-01-01',
            'is_latest' => false,
        ]);

        try {
            DB::transaction(function () use ($status): void {
                RefStatusPegawai::query()->whereKey($status->id)->delete();
                self::fail('Basis data harus menolak penghapusan status yang masih dirujuk riwayat.');
            });
        } catch (QueryException) {
            // FK RESTRICT adalah lapisan terakhir saat penghapusan tidak melewati Action.
        }

        $this->assertDatabaseHas('ref_status_pegawai', ['id' => $status->id]);
        $this->assertDatabaseHas('employee_status_histories', [
            'employee_id' => $employee->id,
            'status_pegawai_id' => $status->id,
        ]);
    }

    public function test_status_yang_belum_dipakai_dapat_dihapus_permanen(): void
    {
        $status = RefStatusPegawai::create([
            'kode' => 'UJI_HAPUS',
            'nama' => 'Uji Hapus',
            'kelompok' => 'Nonaktif',
        ]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_status_pegawai', ['id' => $status->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefStatusPegawai',
            'auditable_id' => $status->id,
        ]);
    }

    public function test_mutasi_status_menghapus_cache_dropdown(): void
    {
        Cache::put('ref.status_pegawai', ['stale'], 3600);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'UJI_CACHE',
                'nama' => 'Uji Cache',
                'kelompok' => 'Nonaktif',
            ])
            ->assertRedirect();

        $this->assertNull(Cache::get('ref.status_pegawai'));
    }

    public function test_admin_kepegawaian_tidak_boleh_mengelola_status(): void
    {
        $status = RefStatusPegawai::query()->where('kode', 'MUTASI')->firstOrFail();
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.store'), [
                'kode' => 'BARU',
                'nama' => 'Baru',
                'kelompok' => 'Nonaktif',
            ])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.status-pegawai.destroy', $status), [])
            ->assertForbidden();

        $this->assertDatabaseMissing('ref_status_pegawai', ['kode' => 'BARU']);
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
