<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Mengunci kontrak fresh install: migrate:fresh membuat sebagian role dan
 * permission lebih dulu, sehingga RbacSeeder yang non-destruktif melewatkan
 * mapping untuk pasangan role-permission yang sudah ada. Migrasi pembuat role
 * wajib menanam matriks defaultnya sendiri agar install baru lengkap tanpa
 * mengorbankan proteksi revoke operator pada database berjalan.
 *
 * Dijalankan di lane serial: migrate:fresh di dalam test tidak aman paralel.
 */
#[Group('serial')]
class RbacFreshInstallMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_grants_complete_default_matrix(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(RbacSeeder::class);

        $this->assertSame(
            $this->sorted($this->expectedAdminPermissions()),
            $this->actualPermissions('admin_kepegawaian')
        );
        $this->assertSame(
            $this->sorted($this->expectedPimpinanPermissions()),
            $this->actualPermissions('pimpinan')
        );
        $this->assertSame(
            $this->sorted($this->expectedKepalaBagianPermissions()),
            $this->actualPermissions('kepala_bagian')
        );
        $this->assertSame(
            $this->sorted($this->expectedPegawaiPermissions()),
            $this->actualPermissions('pegawai')
        );
        // Super Admin menerima semua capability kecuali penangguhan administratif
        // yang default-nya eksklusif milik Admin Kepegawaian.
        $this->assertSame(
            Permission::query()->where('name', '!=', 'cuti.administrative_postponement.manage')
                ->orderBy('name')->pluck('name')->all(),
            $this->actualPermissions('super_admin')
        );
    }

    public function test_rerun_does_not_revoke_operator_matrix_changes(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->seed(RbacSeeder::class);

        $admin = Role::query()->where('name', 'admin_kepegawaian')->firstOrFail();
        $revoked = Permission::query()->where('name', 'cuti.manual.manage')->firstOrFail();
        $admin->permissions()->detach($revoked->id);

        $this->seed(RbacSeeder::class);

        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $admin->id,
            'permission_id' => $revoked->id,
        ]);
    }

    /** @return list<string> */
    private function actualPermissions(string $role): array
    {
        return Role::query()->where('name', $role)->firstOrFail()
            ->permissions()->orderBy('name')->pluck('name')->all();
    }

    /** @param list<string> $names @return list<string> */
    private function sorted(array $names): array
    {
        sort($names);

        return array_values($names);
    }

    /** @return list<string> */
    private function expectedAdminPermissions(): array
    {
        return [
            'employees.read',
            'employees.create',
            'employees.update',
            'employees.import',
            'employees.export',
            'employees.deactivate',
            'employees.restore',
            'employee_histories.read',
            'employee_histories.create',
            'employee_histories.update',
            'employee_histories.delete',
            'employee_histories.export',
            'discipline_records.read',
            'discipline_records.create',
            'discipline_records.delete',
            'employee_families.read',
            'employee_families.create',
            'employee_families.update',
            'employee_families.delete',
            'sk_requirements.manage',
            'audit_logs.read',
            'notifications.read',
            'notifications.update',
            'dokumen_sk.read',
            'dokumen_sk.create',
            'dokumen_sk.update',
            'dokumen_sk.delete',
            'ews.read',
            'cuti.create',
            'cuti.read_own',
            'cuti.read_all',
            'cuti.approve',
            'cuti.configure',
            'cuti.balance.read',
            'cuti.balance.reconcile',
            'cuti.manual.manage',
            'cuti.proof.generate',
            'cuti.cancellation.manage',
            'cuti.administrative_postponement.manage',
            'cuti.kepala_lembaga_documents.manage',
        ];
    }

    /** @return list<string> */
    private function expectedPimpinanPermissions(): array
    {
        return [
            'employees.read',
            'employee_histories.read',
            'employee_histories.export',
            'employee_families.read',
            'discipline_records.read',
            'dokumen_sk.read',
            'ews.read',
            'notifications.read',
            'notifications.update',
            'cuti.create',
            'cuti.read_own',
            'cuti.approve',
            'cuti.read_all',
            'cuti.configure',
            'cuti.balance.read',
        ];
    }

    /** @return list<string> */
    private function expectedKepalaBagianPermissions(): array
    {
        return [
            'notifications.read',
            'notifications.update',
            'cuti.create',
            'cuti.read_own',
            'cuti.approve',
            'cuti.read_all',
            'cuti.configure',
            'cuti.balance.read',
        ];
    }

    /** @return list<string> */
    private function expectedPegawaiPermissions(): array
    {
        return [
            'employees.read_self',
            'notifications.read',
            'notifications.update',
            'cuti.create',
            'cuti.read_own',
            'cuti.approve',
            'cuti.balance.read',
            'employee_families.read',
            'employee_histories.read',
        ];
    }
}
