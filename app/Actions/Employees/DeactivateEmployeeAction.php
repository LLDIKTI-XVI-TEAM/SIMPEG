<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeactivateEmployeeAction
{
    /**
     * Menonaktifkan pegawai memakai soft delete agar riwayat dan relasi tetap dapat dipulihkan.
     */
    public function execute(Employee $employee, Request $request): void
    {
        DB::transaction(function () use ($employee, $request): void {
            $oldValues = $employee->getRawOriginal();
            $employeeId = $employee->id;

            $employee->delete();

            AuditService::log('SOFT_DELETE', 'Employee', $employeeId, $oldValues, null, $request);
        });
    }
}
