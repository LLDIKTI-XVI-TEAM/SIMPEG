<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\User;

final class EmployeeMutationPayload
{
    /**
     * Permission mutasi tidak membuka identitas sensitif dalam respons bagi non-HR.
     * Sembunyikan sebelum serialisasi agar cast encrypted tidak didekripsi; salinan
     * menjaga model hasil mutasi tetap utuh untuk caller dan tidak mengubah kontrak HR.
     *
     * @return array<string, mixed>
     */
    public static function forActor(Employee $employee, ?User $actor): array
    {
        $presented = clone $employee;
        if (! EmployeeIdentifierPrivacy::canManage($actor)) {
            $presented->makeHidden(['nik', 'no_kk', 'nik_hash']);
        }

        return $presented->toArray();
    }
}
