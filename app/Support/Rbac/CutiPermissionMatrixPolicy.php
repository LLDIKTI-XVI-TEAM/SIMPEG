<?php

namespace App\Support\Rbac;

/**
 * Menjaga batas penugasan permission cuti yang telah ditetapkan organisasi.
 *
 * Approval per tahap sengaja tidak ada di sini: hak memutus cuti selalu
 * ditentukan oleh approver pada snapshot approval chain yang aktif.
 */
final class CutiPermissionMatrixPolicy
{
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_ROLES_BY_PERMISSION = [
        'cuti.create' => ['super_admin', 'admin_kepegawaian', 'kepala_bagian', 'pegawai'],
        'cuti.read_all' => ['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian'],
        'cuti.configure' => ['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian'],
        'cuti.balance.reconcile' => ['super_admin', 'admin_kepegawaian'],
        'cuti.manual.manage' => ['super_admin', 'admin_kepegawaian', 'pimpinan'],
    ];

    public static function isAssignableToRole(string $permissionName, string $roleName): bool
    {
        $allowedRoles = self::ALLOWED_ROLES_BY_PERMISSION[$permissionName] ?? null;

        return $allowedRoles === null || in_array($roleName, $allowedRoles, true);
    }
}
