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

    public function test_hak_akses_super_admin_dapat_dikurangi_lewat_matriks(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [$role->id => []],
        ]);

        $this->assertTrue($role->fresh()->permissions->isEmpty());
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

    public function test_matriks_hanya_menugaskan_capability_cuti_rbac(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $pimpinan = Role::query()->where('name', 'pimpinan')->firstOrFail();
        $pegawai = Role::query()->where('name', 'pegawai')->firstOrFail();
        $manual = Permission::query()->where('name', 'cuti.manual.manage')->sole();
        $readAll = Permission::query()->where('name', 'cuti.read_all')->sole();

        $response = $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [
                $pimpinan->id => array_merge($pimpinan->permissions->pluck('id')->all(), [$manual->id]),
                $pegawai->id => array_merge($pegawai->permissions->pluck('id')->all(), [$readAll->id]),
            ],
        ]);

        $response->assertRedirect();
        $this->assertTrue($pimpinan->fresh()->permissions->contains('id', $manual->id));
        $this->assertTrue($pegawai->fresh()->permissions->contains('id', $readAll->id));
    }

    public function test_matriks_dapat_menugaskan_switch_role_ke_semua_role(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $kepalaBagian = Role::query()->where('name', 'kepala_bagian')->firstOrFail();
        $pegawai = Role::query()->where('name', 'pegawai')->firstOrFail();
        $switchRole = Permission::query()->where('name', 'users.switch_role')->sole();

        $response = $this->actingAs($superAdmin)->post(route('rbac.update'), [
            'matrix' => [
                $kepalaBagian->id => array_merge($kepalaBagian->permissions->pluck('id')->all(), [$switchRole->id]),
                $pegawai->id => array_merge($pegawai->permissions->pluck('id')->all(), [$switchRole->id]),
            ],
        ]);

        $response->assertRedirect();
        $this->assertTrue($kepalaBagian->fresh()->permissions->contains('id', $switchRole->id));
        $this->assertTrue($pegawai->fresh()->permissions->contains('id', $switchRole->id));
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
