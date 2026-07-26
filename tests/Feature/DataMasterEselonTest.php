<?php

namespace Tests\Feature;

use App\Models\RefEselon;
use App\Models\RefJabatan;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DataMasterEselonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_dapat_menambah_eselon_dengan_audit(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.store'), [
                'kode' => 'IV.a',
                'nama' => 'Eselon IV.a',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_eselon', ['kode' => 'IV.a', 'nama' => 'Eselon IV.a', 'is_active' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'CREATE', 'auditable_type' => 'RefEselon']);
    }

    public function test_tambah_eselon_menolak_kode_duplikat(): void
    {
        RefEselon::create(['kode' => 'IV.a', 'nama' => 'Eselon IV.a']);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.store'), [
                'kode' => 'IV.a',
                'nama' => 'Duplikat',
            ])
            ->assertSessionHasErrors(['kode']);

        $this->assertSame(1, RefEselon::query()->count());
    }

    public function test_super_admin_dapat_mengubah_eselon_dengan_audit(): void
    {
        $eselon = RefEselon::create(['kode' => 'IV.a', 'nama' => 'Eselon IV.a']);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.update', $eselon), [
                'kode' => 'IV.a',
                'nama' => 'Eselon IV.a (Revisi)',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('ref_eselon', ['id' => $eselon->id, 'nama' => 'Eselon IV.a (Revisi)']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefEselon',
            'auditable_id' => $eselon->id,
        ]);
    }

    public function test_toggle_eselon_menonaktifkan_dan_mengaktifkan_dengan_audit_config_update(): void
    {
        $eselon = RefEselon::create(['kode' => 'IV.a', 'nama' => 'Eselon IV.a']);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.toggle', $eselon), [])
            ->assertRedirect();

        $this->assertFalse($eselon->refresh()->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'RefEselon',
            'auditable_id' => $eselon->id,
        ]);

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.toggle', $eselon), [])
            ->assertRedirect();

        $this->assertTrue($eselon->refresh()->is_active);
    }

    public function test_eselon_yang_belum_dipakai_dapat_dihapus_permanen_dengan_audit(): void
    {
        $eselon = RefEselon::create(['kode' => 'IV.b', 'nama' => 'Eselon IV.b']);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.destroy', $eselon), [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_eselon', ['id' => $eselon->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefEselon',
            'auditable_id' => $eselon->id,
        ]);
    }

    public function test_eselon_yang_dipakai_referensi_jabatan_tidak_dapat_dihapus(): void
    {
        $eselon = RefEselon::create(['kode' => 'II.a', 'nama' => 'Eselon II.a']);
        // FK eselon di ref_jabatan bersifat nullOnDelete sehingga database tidak
        // memblokir penghapusan; guard aplikasi inilah satu-satunya pelindung
        // agar data yang merujuk tidak kehilangan eselonnya diam-diam.
        RefJabatan::create(['nama' => 'Kepala Bagian Umum', 'eselon_id' => $eselon->id]);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.destroy', $eselon), [])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('ref_eselon', ['id' => $eselon->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefEselon',
        ]);
    }

    public function test_mutasi_eselon_menghapus_cache_dropdown(): void
    {
        Cache::put('ref.eselon', ['stale'], 3600);
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.store'), [
                'kode' => 'III.a',
                'nama' => 'Eselon III.a',
            ])
            ->assertRedirect();

        $this->assertNull(Cache::get('ref.eselon'));
    }

    public function test_admin_kepegawaian_tidak_boleh_mengelola_eselon(): void
    {
        $eselon = RefEselon::create(['kode' => 'IV.a', 'nama' => 'Eselon IV.a']);
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.store'), ['kode' => 'I.a', 'nama' => 'Eselon I.a'])
            ->assertForbidden();
        $this->actingAs($user)
            ->postWithCsrf(route('data-master.eselon.destroy', $eselon), [])
            ->assertForbidden();

        $this->assertSame(1, RefEselon::query()->count());
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
