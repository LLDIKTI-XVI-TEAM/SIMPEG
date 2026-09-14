<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeSwitchRoleScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([ReferenceSeeder::class, RbacSeeder::class]);
    }

    public function test_simulasi_pegawai_mempertahankan_scope_global_aktor_asli_pada_detail_edit_dan_update(): void
    {
        $actor = $this->switchActor('super_admin', 'pegawai');
        $target = Employee::factory()->create();

        $this->assertReadableAndEditable($target);
        $this->assertSame('super_admin', $actor->fresh()->role);
        $this->assertNotSame($actor->employee_id, $target->id);
    }

    public function test_simulasi_pegawai_mempertahankan_bawahan_kepala_bagian_sebagai_scope_administratif(): void
    {
        $actor = $this->switchActor('kepala_bagian', 'pegawai');
        $target = Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);
        $outside = Employee::factory()->create();

        $this->assertReadableAndEditable($target);
        $this->get(route('rbac.pegawai.show', $outside))->assertForbidden();
        $this->get(route('rbac.pegawai.edit', $outside))->assertForbidden();
        $this->post(route('rbac.pegawai.update', $outside), ['nama_lengkap' => 'Di Luar Scope'])->assertForbidden();
        $this->putJson(route('api.v1.pegawai.update', $outside), ['nama_lengkap' => 'Di Luar Scope'])->assertForbidden();
        $this->assertNotSame('Di Luar Scope', $outside->fresh()->nama_lengkap);
    }

    public function test_simulasi_pegawai_tidak_mengubah_self_read_paten_menjadi_scope_edit_kepala_bagian(): void
    {
        $actor = $this->switchActor('kepala_bagian', 'pegawai');
        $identity = $actor->employee;
        $originalName = $identity->nama_lengkap;

        // Hak melihat profil sendiri tidak memperluas scope mutasi administratif aktor asli.
        $this->get(route('profil'))->assertOk();
        $this->get(route('rbac.pegawai.edit', $identity))->assertForbidden();
        $this->post(route('rbac.pegawai.update', $identity), ['nama_lengkap' => 'Tidak Disimpan'])->assertForbidden();
        $this->putJson(route('api.v1.pegawai.update', $identity), ['nama_lengkap' => 'Tidak Disimpan'])->assertForbidden();
        $this->assertSame($originalName, $identity->fresh()->nama_lengkap);
    }

    public function test_scope_asli_tidak_melewati_pencabutan_permission_role_target(): void
    {
        $this->switchActor('super_admin', 'pegawai');
        $target = Employee::factory()->create();
        $originalName = $target->nama_lengkap;

        $this->get(route('rbac.pegawai.show', $target))->assertOk();
        $this->get(route('rbac.pegawai.edit', $target))->assertOk();

        Role::where('name', 'pegawai')->firstOrFail()->permissions()->detach(
            Permission::whereIn('name', ['employees.read', 'employees.update'])->pluck('id'),
        );

        $this->get(route('rbac.pegawai.show', $target))->assertForbidden();
        $this->get(route('rbac.pegawai.edit', $target))->assertForbidden();
        $this->post(route('rbac.pegawai.update', $target), ['nama_lengkap' => 'Tidak Disimpan'])->assertForbidden();
        $this->putJson(route('api.v1.pegawai.update', $target), ['nama_lengkap' => 'Tidak Disimpan'])->assertForbidden();
        $this->assertSame($originalName, $target->fresh()->nama_lengkap);
    }

    public static function restrictedEffectiveRoles(): array
    {
        return [['pimpinan'], ['kepala_bagian'], ['pegawai']];
    }

    #[DataProvider('restrictedEffectiveRoles')]
    public function test_scope_global_asli_tidak_membuka_payload_strict_bagi_role_efektif_terbatas(string $effectiveRole): void
    {
        $actor = $this->switchActor('super_admin', $effectiveRole);
        $target = Employee::factory()->create(['kepala_bagian_id' => $actor->employee_id]);

        // Scope record asli tidak membatalkan batas privasi payload mentah role simulasi.
        $this->getJson(route('api.v1.pegawai.show', $target))->assertForbidden();
        if ($effectiveRole === 'pegawai') {
            $this->getJson(route('api.v1.pegawai.show', $actor->employee_id))->assertOk();
        }
    }

    /** Memulai simulasi melalui route nyata agar identity dan permission target diuji bersama. */
    private function switchActor(string $originalRole, string $effectiveRole): User
    {
        $this->grant($originalRole, ['users.switch_role', 'employees.read', 'employees.update']);
        $this->grant($effectiveRole, ['employees.read', 'employees.update']);
        $identity = Employee::factory()->create();
        $actor = User::factory()->create(['role' => $originalRole, 'employee_id' => $identity->id]);

        $this->actingAs($actor)->post(route('switch-role'), ['target_role' => $effectiveRole])
            ->assertRedirect(route('dashboard'))->assertSessionHas('success');
        $actor->refresh();
        $this->assertSame($effectiveRole, $actor->getEffectiveRole());
        $this->assertSame($identity->id, $actor->employee_id);
        $this->actingAs($actor);

        return $actor;
    }

    /** Detail, form, serta kedua jalur simpan harus menjangkau himpunan record yang sama. */
    private function assertReadableAndEditable(Employee $target): void
    {
        $this->get(route('rbac.pegawai.show', $target))->assertOk()->assertSee($target->nama_lengkap);
        $this->get(route('rbac.pegawai.edit', $target))->assertOk();
        $this->post(route('rbac.pegawai.update', $target), ['nama_lengkap' => 'Nama dari Form Simulasi'])
            ->assertSessionHasNoErrors()->assertSessionHas('success')->assertRedirect(route('dashboard'));
        $this->assertSame('Nama dari Form Simulasi', $target->fresh()->nama_lengkap);
        $this->putJson(route('api.v1.pegawai.update', $target), ['nama_lengkap' => 'Nama dari API Simulasi'])->assertOk()
            ->assertJsonMissingPath('employee.nik')->assertJsonMissingPath('employee.no_kk')->assertJsonMissingPath('employee.nik_hash');
        $this->assertSame('Nama dari API Simulasi', $target->fresh()->nama_lengkap);
    }

    private function grant(string $role, array $permissions): void
    {
        Role::where('name', $role)->firstOrFail()->permissions()->syncWithoutDetaching(
            Permission::whereIn('name', $permissions)->pluck('id'),
        );
    }
}
