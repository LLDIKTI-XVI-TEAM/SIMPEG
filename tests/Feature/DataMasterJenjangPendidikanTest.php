<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\RefJenjangPendidikan;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DataMasterJenjangPendidikanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_dapat_menambah_jenjang_dengan_audit(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenjang-pendidikan.store'), [
                'nama' => 'S3 Terapan',
                'urutan' => 10,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_jenjang_pendidikan', ['nama' => 'S3 Terapan', 'urutan' => 10, 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefJenjangPendidikan']);
    }

    public function test_tambah_jenjang_menolak_nama_duplikat(): void
    {
        RefJenjangPendidikan::create(['nama' => 'S1', 'urutan' => 7]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenjang-pendidikan.store'), [
                'nama' => 'S1',
                'urutan' => 8,
            ])
            ->assertSessionHasErrors(['nama']);

        $this->assertSame(1, RefJenjangPendidikan::query()->count());
    }

    public function test_super_admin_dapat_mengubah_jenjang_dengan_audit(): void
    {
        $jenjang = RefJenjangPendidikan::create(['nama' => 'D4', 'urutan' => 6]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenjang-pendidikan.update', $jenjang), [
                'nama' => 'D4/S1 Terapan',
                'urutan' => 6,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_jenjang_pendidikan', ['id' => $jenjang->id, 'nama' => 'D4/S1 Terapan']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefJenjangPendidikan',
            'auditable_id' => $jenjang->id,
        ]);
    }

    public function test_toggle_jenjang_dengan_audit_config_update(): void
    {
        $jenjang = RefJenjangPendidikan::create(['nama' => 'D2', 'urutan' => 4]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenjang-pendidikan.toggle', $jenjang), [])
            ->assertRedirect();

        $this->assertFalse($jenjang->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefJenjangPendidikan',
            'auditable_id' => $jenjang->id,
        ]);
    }

    public function test_jenjang_yang_belum_dipakai_dapat_dihapus_permanen(): void
    {
        $jenjang = RefJenjangPendidikan::create(['nama' => 'D1', 'urutan' => 3]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenjang-pendidikan.destroy', $jenjang), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_jenjang_pendidikan', ['id' => $jenjang->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefJenjangPendidikan',
            'auditable_id' => $jenjang->id,
        ]);
    }

    public function test_jenjang_yang_dipakai_riwayat_pendidikan_tidak_dapat_dihapus(): void
    {
        $jenjang = RefJenjangPendidikan::create(['nama' => 'S2', 'urutan' => 8]);
        $employee = Employee::factory()->create();
        // Meski FK database sudah restrictOnDelete, guard aplikasi tetap wajib
        // agar penolakan tampil sebagai pesan validasi, bukan error database.
        $employee->educationHistories()->create([
            'jenjang_id' => $jenjang->id,
            'nama_institusi' => 'Universitas Sam Ratulangi',
            'tahun_lulus' => 2020,
            'no_ijazah' => 'IJZ-2020-001',
        ]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenjang-pendidikan.destroy', $jenjang), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_jenjang_pendidikan', ['id' => $jenjang->id]);
    }

    public function test_admin_kepegawaian_tidak_boleh_mengelola_jenjang(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.jenjang-pendidikan.store'), ['nama' => 'S1', 'urutan' => 7])
            ->assertForbidden();

        $this->assertSame(0, RefJenjangPendidikan::query()->count());
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
