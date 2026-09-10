<?php

namespace App\Actions\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use App\Support\Rbac\PatenCapability;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SaveRolePermissionMatrixAction
{
    /** Switch Role dapat diberikan oleh matriks kepada setiap role. Targetnya
     * tetap dibatasi secara invariant oleh hierarki di User::canSwitchToRole(). */
    public const SWITCH_ROLE_ASSIGNABLE_ROLES = ['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'];

    /**
     * Menyimpan matriks hak akses peran dan mencatat setiap peran yang berubah.
     *
     * Ketiadaan kunci peran pada matriks dibaca sebagai pelepasan seluruh centang, karena peramban
     * tidak mengirim kunci apa pun untuk peran yang tidak memiliki centang tersisa.
     *
     * Audit ditulis dengan logOrFail di dalam transaksi supaya perubahan kewenangan tidak pernah
     * berlaku tanpa jejak; bila audit gagal, sinkronisasi ikut dibatalkan.
     *
     * @param  array<string, array<int, string>>  $matrix
     * @return int jumlah peran yang berubah
     */
    public function execute(array $matrix, Request $request): int
    {
        return DB::transaction(function () use ($matrix, $request): int {
            $roles = Role::query()->with('permissions')->get();
            $namaPermission = Permission::query()->pluck('name', 'id');
            $jumlahBerubah = 0;

            foreach ($roles as $role) {
                $patenPermissionIds = $role->permissions
                    ->filter(fn (Permission $permission): bool => PatenCapability::isPermissionName($permission->name))
                    ->pluck('id')
                    ->all();
                $sebelum = $role->permissions->pluck('id')->sort()->values()->all();
                $sesudah = collect($matrix[$role->id] ?? [])
                    ->unique()
                    ->filter(function (string $permissionId) use ($namaPermission, $role): bool {
                        $permissionName = $namaPermission->get($permissionId);

                        return is_string($permissionName)
                            && $this->isAssignableToRole($permissionName, $role->name);
                    })
                    ->reject(fn (string $permissionId): bool => PatenCapability::isPermissionName((string) $namaPermission->get($permissionId)))
                    ->merge($patenPermissionIds)
                    ->sort()
                    ->values()
                    ->all();

                if ($sebelum === $sesudah) {
                    continue;
                }

                $role->permissions()->sync($sesudah);
                $jumlahBerubah++;

                // Nama permission disimpan, bukan pengenalnya, agar jejak audit tetap terbaca
                // meskipun daftar permission berubah di kemudian hari.
                AuditService::logOrFail(
                    'CONFIG_UPDATE',
                    'Role',
                    $role->id,
                    ['permissions' => $this->namaTerurut($sebelum, $namaPermission)],
                    ['permissions' => $this->namaTerurut($sesudah, $namaPermission)],
                    $request,
                );
            }

            return $jumlahBerubah;
        });
    }

    private function isAssignableToRole(string $permissionName, string $roleName): bool
    {
        return $permissionName !== 'users.switch_role'
            || in_array($roleName, self::SWITCH_ROLE_ASSIGNABLE_ROLES, true);
    }

    /**
     * @param  array<int, string>  $permissionIds
     * @param  Collection<string, string>  $namaPermission
     * @return array<int, string>
     */
    private function namaTerurut(array $permissionIds, $namaPermission): array
    {
        return collect($permissionIds)
            ->map(fn (string $id): string => (string) $namaPermission->get($id, $id))
            ->sort()
            ->values()
            ->all();
    }
}
