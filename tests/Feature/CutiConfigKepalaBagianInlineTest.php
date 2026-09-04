<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Memastikan widget penetapan Atasan Langsung pada halaman Konfigurasi Approval Cuti
 * memakai endpoint penetapan yang sudah ada dan mengembalikan pengguna ke halaman asal
 * hanya untuk nilai redirect yang di-whitelist.
 */
class CutiConfigKepalaBagianInlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-27 08:00:00');
        $this->seed(RbacSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_widget_penetapan_tampil_saat_pegawai_belum_punya_kepala_bagian(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create([
            'nama_lengkap' => 'Pegawai Tanpa Kabag',
            'nip' => '200000000000000001',
        ]);

        $response = $this->actingAs($actor)->get(route('cuti.config', ['employee_id' => $pegawai->id]));

        $response->assertOk()
            ->assertSee('Penetapan Atasan Langsung')
            ->assertSee(route('pegawai.assign-atasan', $pegawai->id), false)
            ->assertSee('name="redirect_to" value="cuti-config"', false)
            // Form chain belum menawarkan tahap Atasan Langsung sampai penugasan efektif tersedia.
            ->assertDontSee(':name="`steps[${verifiers.length}][approver_employee_id]`"', false)
            ->assertSee('Atasan Langsung belum ditetapkan untuk pegawai. Tetapkan penugasan Atasan Langsung sebelum menyimpan chain.');
    }

    public function test_penetapan_dari_halaman_konfigurasi_kembali_ke_halaman_konfigurasi(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kabag = Employee::factory()->create(['nama_lengkap' => 'Calon Kepala Bagian']);
        $pegawai = Employee::factory()->create(['nama_lengkap' => 'Pegawai Konfigurasi Inline']);

        $response = $this->actingAs($actor)->postWithCsrf(route('pegawai.assign-atasan', $pegawai->id), [
            'kepala_bagian_id' => $kabag->id,
            'effective_date' => '2026-07-27',
            'redirect_to' => 'cuti-config',
        ]);

        $response->assertRedirect(route('cuti.config', ['employee_id' => $pegawai->id]));
        $response->assertSessionHas(
            'success',
            'Atasan Langsung untuk '.$pegawai->nama_lengkap.' berhasil diperbarui.',
        );
        $this->assertDatabaseHas('supervisor_assignments', [
            'employee_id' => $pegawai->id,
            'kepala_bagian_id' => $kabag->id,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $pegawai->id,
            'kepala_bagian_id' => $kabag->id,
        ]);

        // Setelah kembali, tahap Atasan Langsung mengikuti seluruh verifikator dan terisi dari penugasan efektif baru.
        $this->actingAs($actor)
            ->get(route('cuti.config', ['employee_id' => $pegawai->id]))
            ->assertOk()
            ->assertSee(':name="`steps[${verifiers.length}][approver_employee_id]`" value="'.$kabag->id.'"', false);
    }

    public function test_penetapan_tanpa_redirect_to_tetap_kembali_ke_detail_pegawai(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kabag = Employee::factory()->create();
        $pegawai = Employee::factory()->create();

        $response = $this->actingAs($actor)->postWithCsrf(route('pegawai.assign-atasan', $pegawai->id), [
            'kepala_bagian_id' => $kabag->id,
            'effective_date' => '2026-07-27',
        ]);

        $response->assertRedirect(route('pegawai.show', $pegawai->id));
    }

    public function test_redirect_to_di_luar_whitelist_ditolak_validasi(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $kabag = Employee::factory()->create();
        $pegawai = Employee::factory()->create();

        $response = $this->actingAs($actor)->postWithCsrf(route('pegawai.assign-atasan', $pegawai->id), [
            'kepala_bagian_id' => $kabag->id,
            'effective_date' => '2026-07-27',
            'redirect_to' => 'https://evil.example/phishing',
        ]);

        $response->assertSessionHasErrors('redirect_to');
        $this->assertDatabaseCount('supervisor_assignments', 0);
        $this->assertDatabaseHas('employees', [
            'id' => $pegawai->id,
            'kepala_bagian_id' => null,
        ]);
    }

    public function test_error_validasi_penetapan_kembali_ke_halaman_konfigurasi(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();

        $response = $this->actingAs($actor)->postWithCsrf(route('pegawai.assign-atasan', $pegawai->id), [
            // Self-assignment ditolak aturan domain pada action.
            'kepala_bagian_id' => $pegawai->id,
            'effective_date' => '2026-07-27',
            'redirect_to' => 'cuti-config',
        ]);

        $response->assertRedirect(route('cuti.config', ['employee_id' => $pegawai->id]));
        $response->assertSessionHasErrors([
            'kepala_bagian_id' => 'Pegawai tidak bisa menjadi atasan untuk diri sendiri.',
        ]);
        $this->assertDatabaseCount('supervisor_assignments', 0);
    }

    public function test_validasi_http_inline_memakai_label_atasan_langsung(): void
    {
        $actor = User::factory()->superAdmin()->create();
        $pegawai = Employee::factory()->create();

        $response = $this->actingAs($actor)->postWithCsrf(route('pegawai.assign-atasan', $pegawai->id), [
            'kepala_bagian_id' => 'bukan-uuid',
            'effective_date' => '27/07/2026',
            'redirect_to' => 'cuti-config',
        ]);

        $response->assertSessionHasErrors(['kepala_bagian_id', 'effective_date']);

        $errors = session('errors');
        $this->assertStringContainsString('Atasan Langsung', $errors->first('kepala_bagian_id'));
        $this->assertStringNotContainsString('Kepala Bagian', $errors->first('kepala_bagian_id'));
        $this->assertStringContainsString('Tanggal Mulai Penugasan Atasan Langsung', $errors->first('effective_date'));
        $this->assertDatabaseCount('supervisor_assignments', 0);
    }

    public function test_role_pimpinan_tidak_bisa_menetapkan_lewat_route_web(): void
    {
        $actor = User::factory()->pimpinan()->create();
        $kabag = Employee::factory()->create();
        $pegawai = Employee::factory()->create();

        $this->actingAs($actor)
            ->postWithCsrf(route('pegawai.assign-atasan', $pegawai->id), [
                'kepala_bagian_id' => $kabag->id,
                'effective_date' => '2026-07-27',
                'redirect_to' => 'cuti-config',
            ])
            ->assertForbidden();
    }

    /** @param array<string, mixed> $data */
    private function postWithCsrf(string $uri, array $data = []): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, array_merge($data, ['_token' => 'test-token']));
    }
}
