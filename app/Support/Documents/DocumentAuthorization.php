<?php

namespace App\Support\Documents;

use App\Models\User;

class DocumentAuthorization
{
    /**
     * Role yang boleh melihat arsip dan mengunggah dokumen/SK pegawai.
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
        return self::hasManagerRole($user);
    }

    public static function canManage(?User $user): bool
    {
        return self::hasManagerRole($user)
            && $user->hasPermission('employees.update');
    }

    public static function canDelete(?User $user): bool
    {
        return $user !== null && $user->role === 'super_admin';
    }

    private static function hasManagerRole(?User $user): bool
    {
        return $user !== null
            && in_array($user->role, self::MANAGER_ROLES, true);
    }
}
