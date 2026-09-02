<?php

namespace App\Support\Documents;

use App\Models\User;

class DocumentAuthorization
{
    /**
     * Role efektif yang boleh melihat arsip dan mengunggah dokumen tambahan.
     *
     * @var list<string>
     */
    public const MANAGER_ROLES = ['super_admin', 'admin_kepegawaian'];

    public static function allowsLocalApiBypass(): bool
    {
        return app()->environment('local')
            && (bool) config('services.simpeg.disable_employee_api_auth');
    }

    public static function canViewArchive(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Arsip terpusat memuat dokumen lintas pegawai yang sensitif. Permission
        // read saja tidak cukup karena Pimpinan juga memilikinya untuk surface
        // khusus yang sudah dimasking; arsip mentah tetap hanya untuk pengelola.
        return self::hasManagerRole($user)
            && ($user->hasPermission('dokumen_sk.read') || $user->hasPermission('employees.read'));
    }

    public static function canManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasPermission('employees.update');
    }

    private static function hasManagerRole(?User $user): bool
    {
        return $user !== null
            && in_array($user->getEffectiveRole(), self::MANAGER_ROLES, true);
    }
}
