<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perubahan hak akses peran adalah mutasi kewenangan, sehingga wajib meninggalkan jejak audit
 * yang tersimpan di basis data, bukan catatan sementara yang hilang bersama sesi pengguna.
 */
class RolePermissionMatrixAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_perubahan_hak_akses_peran_tercatat_pada_audit_basis_data(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();
        $permissionBaru = Permission::query()
            ->whereNotIn('id', $role->permissions->pluck('id'))
            ->firstOrFail();
        $permissionLama = $role->permissions->pluck('id')->all();

        $response = $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [
                $role->id => array_merge($permissionLama, [$permissionBaru->id]),
            ],
        ]);

        $response->assertRedirect();

        $audit = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'Role')
            ->where('auditable_id', $role->id)
            ->sole();

        $this->assertSame($superAdmin->name, $audit->user_name);
        $this->assertNotContains($permissionBaru->name, $audit->old_values['permissions']);
        $this->assertContains($permissionBaru->name, $audit->new_values['permissions']);
        $this->assertTrue($role->fresh()->permissions->contains('id', $permissionBaru->id));
    }

    public function test_audit_hak_akses_tidak_lagi_ditulis_ke_sesi(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();
        $permissionBaru = Permission::query()
            ->whereNotIn('id', $role->permissions->pluck('id'))
            ->firstOrFail();

        $response = $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [
                $role->id => array_merge($role->permissions->pluck('id')->all(), [$permissionBaru->id]),
            ],
        ]);

        // Catatan berbasis sesi hilang saat pengguna keluar sehingga tidak dapat disebut audit.
        $response->assertSessionMissing('dynamic_audit_logs');
    }

    public function test_matriks_tanpa_perubahan_tidak_menghasilkan_audit(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();

        $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [
                $role->id => $role->permissions->pluck('id')->all(),
            ],
        ]);

        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'Role',
            'auditable_id' => $role->id,
        ]);
    }

    public function test_pengenal_permission_yang_tidak_dikenal_ditolak_tanpa_mengubah_apa_pun(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();
        $sebelum = $role->permissions->pluck('id')->sort()->values()->all();

        $response = $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [
                $role->id => ['bukan-uuid-permission'],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($sebelum, $role->fresh()->permissions->pluck('id')->sort()->values()->all());
        $this->assertDatabaseMissing('audit_logs', ['event' => 'CONFIG_UPDATE']);
    }

    public function test_hak_akses_super_admin_tidak_dapat_dikurangi_lewat_matriks(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $sebelum = $role->permissions->pluck('id')->sort()->values()->all();
        $this->assertNotEmpty($sebelum);

        // Payload sengaja mengosongkan peran super admin. Bila peladen menurutinya, sistem kehilangan
        // satu-satunya peran yang dapat memperbaiki hak akses sehingga tidak ada jalan pulih.
        $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [$role->id => []],
        ]);

        $this->assertSame($sebelum, $role->fresh()->permissions->pluck('id')->sort()->values()->all());
    }

    public function test_hak_akses_peran_biasa_dapat_dikosongkan_ketika_tidak_ada_centang(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();
        $this->assertTrue($role->permissions->isNotEmpty());

        // Peramban tidak mengirim kunci peran ketika seluruh centangnya dilepas, sehingga ketiadaan
        // kunci harus tetap dibaca sebagai permintaan mengosongkan hak akses peran itu.
        $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [],
        ]);

        $this->assertTrue($role->fresh()->permissions->isEmpty());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'Role',
            'auditable_id' => $role->id,
        ]);
    }

    public function test_peran_tanpa_kewenangan_tidak_dapat_mengubah_hak_akses(): void
    {
        $admin = User::factory()->adminKepegawaian()->create();
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('rbac.update'), [
            'matrix' => [$role->id => []],
        ]);

        $response->assertForbidden();
        $this->assertTrue($role->fresh()->permissions->isNotEmpty());
    }

    public function test_tamu_tidak_dapat_mengubah_hak_akses(): void
    {
        $role = Role::query()->where('name', 'pegawai')->firstOrFail();

        $response = $this->post(route('rbac.update'), [
            'matrix' => [$role->id => []],
        ]);

        $response->assertRedirect(route('login'));
        $this->assertTrue($role->fresh()->permissions->isNotEmpty());
    }
}
