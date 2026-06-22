<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RefJenisPegawai;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Menguji penegakan otorisasi pada level permission (bukan hanya role).
 * Otoritas RBAC berada di database SIMPEG; Keycloak hanya untuk autentikasi.
 */
class RbacPermissionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed role + permission + mapping agar pengecekan permission punya data acuan.
        $this->seed(RbacSeeder::class);
        // Reference data dibutuhkan untuk pembuatan pegawai pada uji middleware route.
        $this->seed(ReferenceSeeder::class);
    }

    public function test_user_can_check_permission_from_role_permission_mapping(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->assertTrue($user->hasPermission('employees.create'));
        $this->assertFalse($user->hasPermission('hari_libur.create'));
    }

    public function test_unknown_role_has_no_permission(): void
    {
        // Role yang tidak terdaftar harus fail-closed: tidak ada permission sama sekali.
        $user = User::factory()->create(['role' => 'role_tidak_terdaftar']);

        $this->assertFalse($user->hasPermission('employees.create'));
    }

    public function test_permission_records_are_seeded_idempotently(): void
    {
        // Re-seed tidak boleh menduplikasi permission (firstOrCreate + sync).
        $this->seed(RbacSeeder::class);

        $this->assertSame(8, Permission::count());
        $this->assertTrue(
            Role::where('name', 'super_admin')->firstOrFail()
                ->permissions()->where('name', 'hari_libur.delete')->exists()
        );
    }

    public function test_permission_middleware_allows_user_with_permission(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/employees', $this->validEmployeePayload());

        $response->assertCreated();
    }

    public function test_role_middleware_blocks_role_outside_hari_libur_allowlist(): void
    {
        // Hari libur sengaja dibatasi super_admin pada Fase 1 sebelum cek permission aksi dijalankan.
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/hari-libur', [
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tipe' => 'libur_nasional',
        ]);

        $response->assertForbidden();
    }

    public function test_permission_enforced_even_when_route_role_allows(): void
    {
        // Uji pembeda: tanpa middleware permission, admin_kepegawaian lolos role employees
        // dan request 201. Mencabut permission employees.create harus membuat akses 403,
        // membuktikan penegakan terjadi di level permission, bukan sekadar role.
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();
        $permissionId = Permission::where('name', 'employees.create')->firstOrFail()->id;
        $role->permissions()->detach($permissionId);

        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/employees', $this->validEmployeePayload());

        $response->assertForbidden();
    }

    public function test_admin_kepegawaian_can_read_audit_logs_with_permission(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/audit-logs');

        $response->assertOk();
    }

    public function test_role_middleware_blocks_pegawai_from_audit_logs(): void
    {
        // Audit log adalah route admin; pegawai ditolak pada pagar role sebelum permission dicek.
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/audit-logs');

        $response->assertForbidden();
    }

    /**
     * Helper request POST dengan token CSRF sesi, mengikuti pola test fitur lain.
     */
    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    /**
     * Payload pegawai valid minimal untuk menembus validasi store employee.
     *
     * @return array<string, mixed>
     */
    private function validEmployeePayload(): array
    {
        return [
            'nama_lengkap' => 'Budi Santoso',
            'email' => 'budi.permission@example.com',
            'golongan_terakhir' => 'III/a',
            'jabatan_terakhir' => 'Analis Kepegawaian',
            'kelas_jabatan' => '7',
            'nip' => '198001012006041001',
            'no_hp' => '081234567890',
            'pangkat_terakhir' => 'Penata Muda',
            'pendidikan_terakhir' => 'S1',
            'tanggal_pensiun' => '2038-01-01',
            'prodi_pendidikan_terakhir' => 'Manajemen',
            'jenis_pegawai_id' => RefJenisPegawai::where('nama', 'PNS')->firstOrFail()->id,
            'tanggal_lahir' => '1980-01-01',
        ];
    }
}
