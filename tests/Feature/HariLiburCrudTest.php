<?php

namespace Tests\Feature;

use App\Models\RefHariLibur;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HariLiburCrudTest extends TestCase
{
    use RefreshDatabase;

    private const HARI_LIBUR_ENDPOINT = '/api/v1/hari-libur';

    protected function setUp(): void
    {
        parent::setUp();

        // Seed RBAC agar permission hari_libur.* tersedia untuk middleware permission.
        $this->seed(RbacSeeder::class);
    }

    public function test_super_admin_can_list_hari_libur_with_tipe_label_and_year_filter(): void
    {
        $user = User::factory()->superAdmin()->create();
        RefHariLibur::create([
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);
        RefHariLibur::create([
            'tanggal' => '2027-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2027,
            'is_cuti_bersama' => false,
        ]);
        RefHariLibur::create([
            'tanggal' => '2026-03-20',
            'nama' => 'Cuti Bersama Idul Fitri',
            'tahun' => 2026,
            'is_cuti_bersama' => true,
        ]);

        $this->actingAs($user);
        $response = $this->getJson(self::HARI_LIBUR_ENDPOINT.'?tahun=2026');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.tanggal', '2026-01-01');
        $response->assertJsonPath('data.0.nama', 'Tahun Baru Masehi');
        $response->assertJsonPath('data.0.tahun', 2026);
        $response->assertJsonPath('data.0.is_cuti_bersama', false);
        $response->assertJsonPath('data.0.tipe', 'libur_nasional');
        $response->assertJsonPath('data.0.label_tipe', 'Libur Nasional');
        $response->assertJsonPath('data.1.tanggal', '2026-03-20');
        $response->assertJsonPath('data.1.is_cuti_bersama', true);
        $response->assertJsonPath('data.1.tipe', 'cuti_bersama');
        $response->assertJsonPath('data.1.label_tipe', 'Cuti Bersama');
    }

    public function test_admin_kepegawaian_cannot_list_hari_libur(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson(self::HARI_LIBUR_ENDPOINT.'?tahun=2026');

        $response->assertForbidden();
    }

    public function test_guest_cannot_create_hari_libur(): void
    {
        $response = $this->postJsonWithCsrf(self::HARI_LIBUR_ENDPOINT, [
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertRedirect('/login');
    }

    public function test_admin_kepegawaian_cannot_create_hari_libur(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::HARI_LIBUR_ENDPOINT, [
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_can_create_hari_libur_and_audit_log(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::HARI_LIBUR_ENDPOINT, [
            'tanggal' => '2026-03-20',
            'nama' => 'Cuti Bersama Idul Fitri',
            'tipe' => 'cuti_bersama',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.tanggal', '2026-03-20');
        $response->assertJsonPath('data.nama', 'Cuti Bersama Idul Fitri');
        $response->assertJsonPath('data.tahun', 2026);
        $response->assertJsonPath('data.is_cuti_bersama', true);
        $response->assertJsonPath('data.tipe', 'cuti_bersama');
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

    public function test_create_rejects_invalid_tipe(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::HARI_LIBUR_ENDPOINT, [
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tipe' => 'hari_libur_daerah',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tipe']);
    }

    public function test_create_rejects_duplicate_tanggal(): void
    {
        $user = User::factory()->superAdmin()->create();
        RefHariLibur::create([
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::HARI_LIBUR_ENDPOINT, [
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi Duplikat',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal']);
    }

    public function test_create_rejects_empty_nama(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::HARI_LIBUR_ENDPOINT, [
            'tanggal' => '2026-01-01',
            'nama' => '',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['nama']);
    }

    public function test_create_rejects_invalid_date_format(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::HARI_LIBUR_ENDPOINT, [
            'tanggal' => '01/01/2026',
            'nama' => 'Tahun Baru Masehi',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal']);
    }

    public function test_create_rejects_multiple_validation_errors(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf(self::HARI_LIBUR_ENDPOINT, [
            'tanggal' => '01-01-2026',
            'nama' => '',
            'tipe' => 'invalid_type',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal', 'nama', 'tipe']);
    }

    public function test_super_admin_can_update_hari_libur_and_audit_log(): void
    {
        $user = User::factory()->superAdmin()->create();
        $hariLibur = RefHariLibur::create([
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf(self::HARI_LIBUR_ENDPOINT."/{$hariLibur->id}", [
            'tanggal' => '2026-01-02',
            'nama' => 'Cuti Bersama Tahun Baru',
            'tipe' => 'cuti_bersama',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.tanggal', '2026-01-02');
        $response->assertJsonPath('data.nama', 'Cuti Bersama Tahun Baru');
        $response->assertJsonPath('data.tahun', 2026);
        $response->assertJsonPath('data.is_cuti_bersama', true);
        $response->assertJsonPath('data.tipe', 'cuti_bersama');
        $this->assertDatabaseHas('ref_hari_libur', [
            'id' => $hariLibur->id,
            'nama' => 'Cuti Bersama Tahun Baru',
            'tahun' => 2026,
            'is_cuti_bersama' => true,
        ]);
        $this->assertSame('2026-01-02', $hariLibur->refresh()->tanggal->format('Y-m-d'));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'UPDATE',
            'auditable_type' => 'RefHariLibur',
            'auditable_id' => $hariLibur->id,
        ]);
    }

    public function test_update_rejects_duplicate_tanggal_except_current_record(): void
    {
        $user = User::factory()->superAdmin()->create();
        RefHariLibur::create([
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);
        $hariLibur = RefHariLibur::create([
            'tanggal' => '2026-03-20',
            'nama' => 'Cuti Bersama Idul Fitri',
            'tahun' => 2026,
            'is_cuti_bersama' => true,
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf(self::HARI_LIBUR_ENDPOINT."/{$hariLibur->id}", [
            'tanggal' => '2026-01-01',
            'nama' => 'Duplikat Tanggal',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['tanggal']);
    }

    public function test_super_admin_can_delete_hari_libur_and_audit_log(): void
    {
        $user = User::factory()->superAdmin()->create();
        $hariLibur = RefHariLibur::create([
            'tanggal' => '2026-12-25',
            'nama' => 'Hari Raya Natal',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);

        $this->actingAs($user);
        $response = $this->deleteJsonWithCsrf(self::HARI_LIBUR_ENDPOINT."/{$hariLibur->id}");

        $response->assertOk();
        $response->assertJsonPath('message', 'Hari libur berhasil dihapus.');
        $this->assertDatabaseMissing('ref_hari_libur', [
            'id' => $hariLibur->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefHariLibur',
            'auditable_id' => $hariLibur->id,
        ]);
    }

    public function test_pegawai_cannot_delete_hari_libur(): void
    {
        $user = User::factory()->pegawai()->create();
        $hariLibur = RefHariLibur::create([
            'tanggal' => '2026-12-25',
            'nama' => 'Hari Raya Natal',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);

        $this->actingAs($user);
        $response = $this->deleteJsonWithCsrf(self::HARI_LIBUR_ENDPOINT."/{$hariLibur->id}");

        $response->assertForbidden();
        $this->assertDatabaseHas('ref_hari_libur', [
            'id' => $hariLibur->id,
        ]);
    }

    public function test_guest_cannot_update_hari_libur(): void
    {
        $hariLibur = RefHariLibur::create([
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);

        $response = $this->putJsonWithCsrf(self::HARI_LIBUR_ENDPOINT."/{$hariLibur->id}", [
            'tanggal' => '2026-01-02',
            'nama' => 'Tahun Baru Diubah',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseHas('ref_hari_libur', [
            'id' => $hariLibur->id,
            'nama' => 'Tahun Baru Masehi',
        ]);
    }

    public function test_admin_kepegawaian_cannot_update_hari_libur(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $hariLibur = RefHariLibur::create([
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf(self::HARI_LIBUR_ENDPOINT."/{$hariLibur->id}", [
            'tanggal' => '2026-01-02',
            'nama' => 'Tahun Baru Diubah',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('ref_hari_libur', [
            'id' => $hariLibur->id,
            'nama' => 'Tahun Baru Masehi',
        ]);
    }

    public function test_update_returns_404_for_unknown_id(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);
        $response = $this->putJsonWithCsrf(self::HARI_LIBUR_ENDPOINT.'/01HZZZZZZZZZZZZZZZZZZZZZZZZ', [
            'tanggal' => '2026-01-02',
            'nama' => 'Tahun Baru Diubah',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertNotFound();
    }

    public function test_guest_cannot_delete_hari_libur(): void
    {
        $hariLibur = RefHariLibur::create([
            'tanggal' => '2026-12-25',
            'nama' => 'Hari Raya Natal',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);

        $response = $this->deleteJsonWithCsrf(self::HARI_LIBUR_ENDPOINT."/{$hariLibur->id}");

        $response->assertRedirect('/login');
        $this->assertDatabaseHas('ref_hari_libur', [
            'id' => $hariLibur->id,
        ]);
    }

    public function test_delete_returns_404_for_unknown_id(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);
        $response = $this->deleteJsonWithCsrf(self::HARI_LIBUR_ENDPOINT.'/01HZZZZZZZZZZZZZZZZZZZZZZZZ');

        $response->assertNotFound();
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function putJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->putJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function deleteJsonWithCsrf(string $uri)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->deleteJson($uri, [], ['X-CSRF-TOKEN' => 'test-token']);
    }
}
