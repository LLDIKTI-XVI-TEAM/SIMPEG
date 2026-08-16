<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RefJenisPegawai;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $this->assertTrue($user->hasPermission('employees.read'));
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

        // 24 permission non-cuti + 13 permission modul cuti + 1 permission data referensi.
        $this->assertSame(38, Permission::count());
        $this->assertTrue(
            Role::where('name', 'super_admin')->firstOrFail()
                ->permissions()->where('name', 'hari_libur.delete')->exists()
        );
        $this->assertTrue(Permission::where('name', 'employees.deactivate')->exists());
        $this->assertTrue(Permission::where('name', 'employees.restore')->exists());
    }

    public function test_reference_table_permission_only_belongs_to_super_admin(): void
    {
        $permission = Permission::where('name', 'reference_tables.manage')->firstOrFail();

        $this->assertSame('reference_tables', $permission->module);
        $this->assertSame('Mengelola data referensi SIMPEG', $permission->description);
        $this->assertTrue(Role::where('name', 'super_admin')->firstOrFail()->permissions()->whereKey($permission->id)->exists());

        foreach (['admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'] as $roleName) {
            $this->assertFalse(Role::where('name', $roleName)->firstOrFail()->permissions()->whereKey($permission->id)->exists());
        }
    }

    public function test_permission_data_migration_backfills_existing_database_without_running_seeder(): void
    {
        Permission::where('name', 'reference_tables.manage')->delete();

        $migration = require database_path('migrations/2026_08_17_000002_add_reference_tables_manage_permission.php');
        $migration->up();
        $migration->up();

        $permission = Permission::where('name', 'reference_tables.manage')->firstOrFail();
        $superAdmin = Role::where('name', 'super_admin')->firstOrFail();

        $this->assertSame('reference_tables', $permission->module);
        $this->assertSame('Mengelola data referensi SIMPEG', $permission->description);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $superAdmin->id,
            'permission_id' => $permission->id,
        ]);
        $this->assertSame(1, DB::table('role_permissions')->where('permission_id', $permission->id)->count());
    }

    public function test_permission_middleware_allows_user_with_permission(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->postJsonWithCsrf('/api/v1/pegawai', $this->validEmployeePayload());

        $response->assertCreated();
    }

    public function test_role_middleware_blocks_role_outside_hari_libur_allowlist(): void
    {
        // Hari libur sengaja dibatasi super_admin sebelum cek permission aksi dijalankan.
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
        $response = $this->postJsonWithCsrf('/api/v1/pegawai', $this->validEmployeePayload());

        $response->assertForbidden();
    }

    public function test_admin_kepegawaian_can_read_audit_logs_with_permission(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/audit-log');

        $response->assertOk();
    }

    public function test_role_middleware_blocks_pegawai_from_audit_logs(): void
    {
        // Audit log adalah route admin; pegawai ditolak pada pagar role sebelum permission dicek.
        $user = User::factory()->pegawai()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/audit-log');

        $response->assertForbidden();
    }

    public function test_old_audit_logs_endpoint_is_not_available(): void
    {
        $user = User::factory()->adminKepegawaian()->create();

        $this->actingAs($user);
        $response = $this->getJson('/api/v1/audit-logs');

        $response->assertNotFound();
    }

    public function test_employee_histories_permissions_exist(): void
    {
        // Verifikasi permission employee_histories.read dan employee_histories.create tersedia.
        $this->assertTrue(
            Permission::where('name', 'employee_histories.read')->exists(),
            'Permission employee_histories.read harus ada di database'
        );
        $this->assertTrue(
            Permission::where('name', 'employee_histories.create')->exists(),
            'Permission employee_histories.create harus ada di database'
        );
    }

    public function test_admin_kepegawaian_has_employee_histories_create_permission(): void
    {
        // admin_kepegawaian harus memiliki permission employee_histories.create.
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();

        $this->assertTrue(
            $role->permissions()->where('name', 'employee_histories.create')->exists(),
            'admin_kepegawaian harus memiliki permission employee_histories.create'
        );
    }

    public function test_discipline_record_permissions_exist_and_are_assigned_to_admin_kepegawaian(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();

        $this->assertTrue(Permission::where('name', 'discipline_records.read')->exists());
        $this->assertTrue(Permission::where('name', 'discipline_records.create')->exists());
        $this->assertTrue($role->permissions()->where('name', 'discipline_records.read')->exists());
        $this->assertTrue($role->permissions()->where('name', 'discipline_records.create')->exists());
    }

    public function test_discipline_delete_permission_is_not_seeded(): void
    {
        $this->assertFalse(Permission::where('name', 'discipline_records.delete')->exists());
    }

    public function test_employee_family_permissions_exist_and_are_assigned_to_admin_kepegawaian(): void
    {
        $role = Role::where('name', 'admin_kepegawaian')->firstOrFail();

        foreach (['read', 'create', 'update', 'delete'] as $action) {
            $permission = "employee_families.{$action}";

            $this->assertTrue(Permission::where('name', $permission)->exists());
            $this->assertTrue($role->permissions()->where('name', $permission)->exists());
        }
    }

    public function test_pegawai_only_has_read_permissions_for_family_and_employee_histories(): void
    {
        $role = Role::where('name', 'pegawai')->firstOrFail();

        $this->assertTrue($role->permissions()->where('name', 'employee_families.read')->exists());
        $this->assertTrue($role->permissions()->where('name', 'employee_histories.read')->exists());

        foreach ([
            'employee_families.create',
            'employee_families.update',
            'employee_families.delete',
            'employee_histories.create',
            'employee_histories.update',
            'employee_histories.delete',
        ] as $permission) {
            if (Permission::where('name', $permission)->exists()) {
                $this->assertFalse(
                    $role->permissions()->where('name', $permission)->exists(),
                    "Role pegawai tidak boleh memiliki permission {$permission}.",
                );
            }
        }
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
