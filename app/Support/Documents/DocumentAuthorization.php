<?php

namespace App\Support\Documents;

use App\Models\User;

class DocumentAuthorization
{
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

        return $user->hasPermission('dokumen_sk.read');
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
}
