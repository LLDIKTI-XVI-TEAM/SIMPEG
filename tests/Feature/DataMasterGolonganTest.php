<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefGolongan;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DataMasterGolonganTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_dapat_menambah_golongan_dengan_audit(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.store'), [
                'kode' => 'III/a',
                'nama' => 'Penata Muda',
                'urutan' => 9,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_golongan', ['kode' => 'III/a', 'nama' => 'Penata Muda', 'urutan' => 9, 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefGolongan']);
    }

    public function test_tambah_golongan_menolak_kode_duplikat(): void
    {
        RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata Muda', 'urutan' => 9]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.store'), [
                'kode' => 'III/a',
                'nama' => 'Duplikat',
            ])
            ->assertSessionHasErrors(['kode']);

        $this->assertSame(1, RefGolongan::query()->count());
    }

    public function test_super_admin_dapat_mengubah_golongan_dengan_audit(): void
    {
        $golongan = RefGolongan::create(['kode' => 'III/a', 'nama' => 'Penata', 'urutan' => 9]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.update', $golongan), [
                'kode' => 'III/a',
                'nama' => 'Penata Muda',
                'urutan' => 9,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_golongan', ['id' => $golongan->id, 'nama' => 'Penata Muda']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefGolongan',
            'auditable_id' => $golongan->id,
        ]);
    }

    public function test_toggle_golongan_dengan_audit_config_update(): void
    {
        $golongan = RefGolongan::create(['kode' => 'I/a', 'nama' => 'Juru Muda', 'urutan' => 1]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.toggle', $golongan), [])
            ->assertRedirect();

        $this->assertFalse($golongan->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefGolongan',
            'auditable_id' => $golongan->id,
        ]);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.toggle', $golongan), [])
            ->assertRedirect();

        $this->assertTrue($golongan->refresh()->is_active);
    }

    public function test_golongan_yang_belum_dipakai_dapat_dihapus_permanen(): void
    {
        $golongan = RefGolongan::create(['kode' => 'I/b', 'nama' => 'Juru Muda Tingkat 1', 'urutan' => 2]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.destroy', $golongan), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_golongan', ['id' => $golongan->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefGolongan',
            'auditable_id' => $golongan->id,
        ]);
    }

    public function test_golongan_yang_dipakai_riwayat_pangkat_tidak_dapat_dihapus(): void
    {
        $golongan = RefGolongan::create(['kode' => 'IV/a', 'nama' => 'Pembina', 'urutan' => 13]);
        $employee = Employee::factory()->create();
        // FK rank_histories.golongan_id memang restrictOnDelete, tetapi guard
        // aplikasi tetap wajib agar penolakan tampil sebagai pesan validasi,
        // bukan error database.
        $employee->rankHistories()->create([
            'golongan_id' => $golongan->id,
            'tmt_pangkat' => '2020-04-01',
            'no_sk' => 'SK-2020-001',
            'tanggal_sk' => '2020-03-15',
        ]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.destroy', $golongan), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_golongan', ['id' => $golongan->id]);
    }

    public function test_mutasi_golongan_menghapus_cache_dropdown(): void
    {
        Cache::put('ref.golongan', ['stale'], 3600);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.store'), [
                'kode' => 'II/a',
                'nama' => 'Pengatur Muda',
                'urutan' => 5,
            ])
            ->assertRedirect();

        $this->assertNull(Cache::get('ref.golongan'));
    }

    public function test_admin_kepegawaian_tidak_boleh_mengelola_golongan(): void
    {
        $golongan = RefGolongan::create(['kode' => 'III/c', 'nama' => 'Penata', 'urutan' => 11]);
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.store'), ['kode' => 'III/d', 'nama' => 'Penata Tingkat 1'])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.golongan.destroy', $golongan), [])
            ->assertForbidden();

        $this->assertSame(1, RefGolongan::query()->count());
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
