<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DataMasterJenisJabatanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_dapat_menambah_jenis_jabatan_dengan_audit(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.store'), [
                'nama' => 'Fungsional Umum',
                'maks_usia_pensiun' => 58,
                'catatan' => 'Default umum pelaksana.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_jenis_jabatan', [
            'nama' => 'Fungsional Umum',
            'maks_usia_pensiun' => 58,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefJenisJabatan']);
    }

    public function test_tambah_jenis_jabatan_menolak_nama_duplikat(): void
    {
        RefJenisJabatan::create(['nama' => 'Struktural', 'maks_usia_pensiun' => 60]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.store'), [
                'nama' => 'Struktural',
                'maks_usia_pensiun' => 58,
            ])
            ->assertSessionHasErrors(['nama']);

        $this->assertSame(1, RefJenisJabatan::query()->count());
    }

    public function test_super_admin_dapat_mengubah_jenis_jabatan_dengan_audit(): void
    {
        $jenis = RefJenisJabatan::create(['nama' => 'Struktural', 'maks_usia_pensiun' => 58]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.update', $jenis), [
                'nama' => 'Struktural',
                'maks_usia_pensiun' => 60,
                'catatan' => 'Disesuaikan hasil rapat.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_jenis_jabatan', ['id' => $jenis->id, 'maks_usia_pensiun' => 60]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefJenisJabatan',
            'auditable_id' => $jenis->id,
        ]);
    }

    public function test_toggle_jenis_jabatan_dengan_audit_config_update(): void
    {
        $jenis = RefJenisJabatan::create(['nama' => 'Fungsional Tertentu', 'maks_usia_pensiun' => 60]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.toggle', $jenis), [])
            ->assertRedirect();

        $this->assertFalse($jenis->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefJenisJabatan',
            'auditable_id' => $jenis->id,
        ]);
    }

    public function test_jenis_jabatan_yang_belum_dipakai_dapat_dihapus_permanen(): void
    {
        $jenis = RefJenisJabatan::create(['nama' => 'Pelaksana Teknis', 'maks_usia_pensiun' => 58]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.destroy', $jenis), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_jenis_jabatan', ['id' => $jenis->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefJenisJabatan',
            'auditable_id' => $jenis->id,
        ]);
    }

    public function test_jenis_jabatan_yang_dipakai_riwayat_jabatan_tidak_dapat_dihapus(): void
    {
        $jenis = RefJenisJabatan::create(['nama' => 'Struktural', 'maks_usia_pensiun' => 60]);
        $employee = Employee::factory()->create();
        // FK position_histories.jenis_jabatan_id restrictOnDelete; guard
        // aplikasi tetap wajib agar penolakan tampil sebagai pesan validasi,
        // bukan error database.
        $employee->positionHistories()->create([
            'jenis_jabatan_id' => $jenis->id,
            'nama_jabatan' => 'Kepala Bagian Umum',
            'tmt_jabatan' => '2021-01-01',
            'no_sk' => 'SK-2021-001',
            'tanggal_sk' => '2020-12-15',
        ]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.destroy', $jenis), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_jenis_jabatan', ['id' => $jenis->id]);
    }

    public function test_jenis_jabatan_yang_dipakai_referensi_jabatan_tidak_dapat_dihapus(): void
    {
        $jenis = RefJenisJabatan::create(['nama' => 'Fungsional Tertentu', 'maks_usia_pensiun' => 60]);
        // FK ref_jabatan.jenis_jabatan_id bersifat nullOnDelete sehingga
        // database tidak memblokir penghapusan; guard aplikasi inilah
        // satu-satunya pelindung agar master jabatan tidak kehilangan jenis
        // jabatannya diam-diam (sumber fallback BUP pensiun).
        RefJabatan::create(['nama' => 'Analis Kepegawaian', 'jenis_jabatan_id' => $jenis->id]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.destroy', $jenis), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_jenis_jabatan', ['id' => $jenis->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefJenisJabatan',
        ]);
    }

    public function test_mutasi_jenis_jabatan_menghapus_cache_dropdown(): void
    {
        // ref.jabatan_with_jenis ikut di-flush karena cache itu menyimpan
        // snapshot relasi jenisJabatan hasil eager-load.
        Cache::put('ref.jenis_jabatan', ['stale'], 3600);
        Cache::put('ref.jabatan_with_jenis', ['stale'], 3600);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.store'), [
                'nama' => 'Jabatan Akademik / Dosen',
                'maks_usia_pensiun' => 65,
            ])
            ->assertRedirect();

        $this->assertNull(Cache::get('ref.jenis_jabatan'));
        $this->assertNull(Cache::get('ref.jabatan_with_jenis'));
    }

    public function test_admin_kepegawaian_tidak_boleh_mengelola_jenis_jabatan(): void
    {
        $jenis = RefJenisJabatan::create(['nama' => 'Struktural', 'maks_usia_pensiun' => 60]);
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.store'), ['nama' => 'Baru', 'maks_usia_pensiun' => 58])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenis-jabatan.destroy', $jenis), [])
            ->assertForbidden();

        $this->assertSame(1, RefJenisJabatan::query()->count());
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
