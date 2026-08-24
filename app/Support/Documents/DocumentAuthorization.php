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
        // Arsip dokumen berisi data pegawai lintas unit: selain role pengelola,
        // akses baca tetap mensyaratkan izin employees.read agar pencabutan izin
        // baca pegawai tidak meninggalkan celah akses ke arsip terpusat.
        return self::hasManagerRole($user)
            && $user->hasPermission('employees.read');
    }

    public static function canManage(?User $user): bool
    {
        return self::hasManagerRole($user)
            && $user->hasPermission('employees.update');
    }

    private static function hasManagerRole(?User $user): bool
    {
        return $user !== null
            && in_array($user->getEffectiveRole(), self::MANAGER_ROLES, true);
    }
}
