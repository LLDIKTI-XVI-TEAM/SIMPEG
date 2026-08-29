<?php

namespace App\Services\Employees;

use App\Models\User;

/** Menjaga invariant role efektif dan permission untuk otorisasi lifecycle pegawai. */
final class EmployeeLifecycleAuthorization
{
    public const RESTORE_PERMISSION = 'employees.restore';

    /** @var list<string> */
    private const RESTORE_ROLES = [
        'super_admin',
        'admin_kepegawaian',
    ];

    /** Reaktivasi wajib lolos role efektif dan permission secara bersamaan. */
    public function canRestore(?User $user): bool
    {
        return $user instanceof User
            && $this->effectiveRoleAllows($user->getEffectiveRole(), self::RESTORE_PERMISSION)
            && $user->hasPermission(self::RESTORE_PERMISSION);
    }

    /**
     * Permission lifecycle lain tetap permission-driven; reaktivasi memiliki allowlist
     * role tambahan agar drift konfigurasi RBAC tidak memperluas kewenangan pemulihan.
     */
    public function effectiveRoleAllows(?string $effectiveRole, string $permission): bool
    {
        if ($permission !== self::RESTORE_PERMISSION) {
            return true;
        }

        if (in_array($effectiveRole, self::RESTORE_ROLES, true)) {
            return true;
        }

        return $effectiveRole === 'local_api_bypass'
            && app()->environment('local')
            && config('services.simpeg.disable_employee_api_auth');
    }
}
