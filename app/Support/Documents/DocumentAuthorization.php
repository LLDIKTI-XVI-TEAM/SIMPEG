<?php

namespace App\Support\Documents;

use App\Models\User;

class DocumentAuthorization
{
    /**
     * Role efektif yang dapat membuka arsip lintas pegawai saat memiliki permission.
     * Arsip tetap read-only; mutasi dokumen mengikuti canManage().
     *
     * @var list<string>
     */
    public const ARCHIVE_VIEWER_ROLES = ['super_admin', 'admin_kepegawaian', 'pimpinan'];

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

        return self::hasArchiveViewerRole($user)
            && ($user->hasPermission('dokumen_sk.read') || $user->hasPermission('employees.read'));
    }

    public static function canManage(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasPermission('dokumen_sk.create')
            || $user->hasPermission('dokumen_sk.update')
            || $user->hasPermission('dokumen_sk.delete');
    }

    public static function canCreate(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasPermission('dokumen_sk.create');
    }

    public static function canUpdate(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasPermission('dokumen_sk.update');
    }

    public static function canDelete(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasPermission('dokumen_sk.delete');
    }

    private static function hasArchiveViewerRole(?User $user): bool
    {
        return $user !== null
            && in_array($user->getEffectiveRole(), self::ARCHIVE_VIEWER_ROLES, true);
    }
}
