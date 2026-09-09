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

    /**
     * Syarat membuka arsip terpusat (halaman + API daftar).
     *
     * Pimpinan dikecualikan dari arsip lintas pegawai meski memegang grant
     * (keputusan stakeholder). Kepala Bagian/Pegawai ter-scope bawahan/milik
     * sendiri oleh controller/action sehingga cukup membawa dokumen_sk.read;
     * role tak ter-scope wajib juga memegang employees.read.
     */
    public static function canBrowseArchive(?User $user): bool
    {
        if ($user === null || ! $user->hasPermission('dokumen_sk.read')) {
            return false;
        }

        $role = $user->getEffectiveRole();

        if ($role === 'pimpinan') {
            return false;
        }

        if (in_array($role, ['kepala_bagian', 'pegawai'], true)) {
            return true;
        }

        return $user->hasPermission('employees.read');
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
