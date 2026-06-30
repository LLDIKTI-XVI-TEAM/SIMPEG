<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RestoreEmployeeAction
{
    /**
     * Mengaktifkan kembali pegawai soft-deleted tanpa mengubah relasi historisnya.
     */
    public function execute(Employee $employee, Request $request): Employee
    {
        return DB::transaction(function () use ($employee, $request): Employee {
            $oldValues = $employee->getRawOriginal();

            $employee->restore();
            $employee->refresh();

            AuditService::log('RESTORE', 'Employee', $employee->id, $oldValues, $employee->getRawOriginal(), $request);

            return $employee;
        });
    }
}
