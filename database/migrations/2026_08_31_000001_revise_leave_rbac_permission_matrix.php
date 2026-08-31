<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REMOVED_PERMISSIONS = [
        'cuti.approve_stage1',
        'cuti.approve_stage2',
        'cuti.approve_stage3',
        'cuti.configure_chain',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $permissions = [
                'cuti.create' => 'Mengajukan permohonan cuti',
                'cuti.read_own' => 'Melihat pengajuan cuti milik sendiri',
                'cuti.read_all' => 'Melihat seluruh pengajuan cuti (monitor)',
                'cuti.approve' => 'Mengambil keputusan approval cuti sesuai assignment aktif',
                'cuti.configure' => 'Mengonfigurasi approval chain cuti',
                'cuti.balance.read' => 'Melihat saldo cuti',
                'cuti.balance.reconcile' => 'Mencatat dan memperbaiki fakta pemakaian serta saldo cuti',
                'cuti.manual.manage' => 'Mencatat, mengoreksi, dan membatalkan pemakaian cuti manual',
                'cuti.proof.generate' => 'Membuat ulang bukti/formulir cuti resmi setelah approval final',
            ];

            foreach ($permissions as $name => $description) {
                Permission::query()->updateOrCreate(
                    ['name' => $name],
                    ['module' => 'cuti', 'description' => $description],
                );
            }

            $permissionsByName = Permission::query()->whereIn('name', array_keys($permissions))
                ->get()
                ->keyBy('name');
            $rolesByName = Role::query()->whereIn('name', [
                'super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai',
            ])->get()->keyBy('name');

            $defaults = [
                'super_admin' => [
                    'cuti.create', 'cuti.read_own', 'cuti.read_all', 'cuti.approve', 'cuti.configure',
                    'cuti.balance.read', 'cuti.balance.reconcile', 'cuti.manual.manage', 'cuti.proof.generate',
                ],
                'admin_kepegawaian' => [
                    'cuti.create', 'cuti.read_own', 'cuti.read_all', 'cuti.approve', 'cuti.configure',
                    'cuti.balance.read', 'cuti.balance.reconcile', 'cuti.manual.manage', 'cuti.proof.generate',
                ],
                'pimpinan' => [
                    'cuti.read_own', 'cuti.read_all', 'cuti.approve', 'cuti.configure', 'cuti.balance.read',
                ],
                'kepala_bagian' => [
                    'cuti.create', 'cuti.read_own', 'cuti.read_all', 'cuti.approve', 'cuti.configure', 'cuti.balance.read',
                ],
                'pegawai' => [
                    'cuti.create', 'cuti.read_own', 'cuti.approve', 'cuti.balance.read',
                ],
            ];

            foreach ($defaults as $roleName => $permissionNames) {
                $role = $rolesByName->get($roleName);

                if ($role === null) {
                    continue;
                }

                $permissionIds = collect($permissionNames)
                    ->map(fn (string $name): ?string => $permissionsByName->get($name)?->id)
                    ->filter()
                    ->all();
                $role->permissions()->syncWithoutDetaching($permissionIds);
            }

            // Terapkan batas penugasan permission pada data yang sudah berjalan.
            $this->detachPermissionsFromRoles($rolesByName, $permissionsByName, 'cuti.create', ['pimpinan']);
            $this->detachPermissionsFromRoles($rolesByName, $permissionsByName, 'cuti.read_all', ['pegawai']);
            $this->detachPermissionsFromRoles($rolesByName, $permissionsByName, 'cuti.configure', ['pegawai']);
            $this->detachPermissionsFromRoles($rolesByName, $permissionsByName, 'cuti.balance.reconcile', ['pimpinan', 'kepala_bagian', 'pegawai']);
            $this->detachPermissionsFromRoles($rolesByName, $permissionsByName, 'cuti.manual.manage', ['kepala_bagian', 'pegawai']);

            // Tidak ada permission RBAC per stage. Workflow approval dan data stage tidak diubah.
            $removed = Permission::query()->whereIn('name', self::REMOVED_PERMISSIONS)->get();
            foreach ($removed as $permission) {
                DB::table('role_permissions')->where('permission_id', $permission->id)->delete();
                $permission->delete();
            }
        });
    }

    public function down(): void
    {
        // Perubahan kebijakan RBAC tidak diputar balik otomatis agar rollback kode tidak memperluas akses tanpa keputusan baru.
    }

    /**
     * @param \Illuminate\Support\Collection<string, Role> $rolesByName
     * @param \Illuminate\Support\Collection<string, Permission> $permissionsByName
     * @param array<int, string> $roleNames
     */
    private function detachPermissionsFromRoles($rolesByName, $permissionsByName, string $permissionName, array $roleNames): void
    {
        $permission = $permissionsByName->get($permissionName);

        if ($permission === null) {
            return;
        }

        foreach ($roleNames as $roleName) {
            $role = $rolesByName->get($roleName);

            if ($role !== null) {
                $role->permissions()->detach($permission->id);
            }
        }
    }
};
