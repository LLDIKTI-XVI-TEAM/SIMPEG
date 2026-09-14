<?php

namespace App\Support\Employees;

use App\Models\User;

final class EmployeeIdentifierPrivacy
{
    /** Permission edit umum tidak memberikan akses plaintext identitas kependudukan. */
    public static function canManage(?User $actor): bool
    {
        return $actor !== null
            && in_array($actor->getEffectiveRole(), ['super_admin', 'admin_kepegawaian'], true);
    }
}
