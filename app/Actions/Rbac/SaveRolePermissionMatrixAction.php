<?php

namespace App\Actions\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SaveRolePermissionMatrixAction
{
    /**
     * Peran yang tidak boleh kehilangan kewenangan lewat matriks.
     *
     * Antarmuka menampilkan centang Super Admin dalam keadaan terkunci, namun penjagaan di
     * antarmuka bukan kontrol akses. Tanpa penjagaan di peladen, satu permintaan tanpa kunci
     * peran ini akan mengosongkan hak aksesnya dan menghapus satu-satunya jalan memulihkan RBAC.
     */
    private const PROTECTED_ROLES = ['super_admin'];

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
                if (in_array($role->name, self::PROTECTED_ROLES, true)) {
                    continue;
                }

                $sebelum = $role->permissions->pluck('id')->sort()->values()->all();
                $sesudah = collect($matrix[$role->id] ?? [])
                    ->unique()
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
