<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\Request;

class UpdateEmployeePerformanceFlagAction
{
    /**
     * Mengubah flag kinerja manual dan mencatat audit agar eligibility EWS dapat ditelusuri.
     */
    public function execute(Employee $employee, bool $isKinerjaBaik, Request $request): Employee
    {
        $before = ['is_kinerja_baik' => $employee->is_kinerja_baik];

        $employee->update([
            'is_kinerja_baik' => $isKinerjaBaik,
        ]);

        $employee->refresh();

        $after = ['is_kinerja_baik' => $employee->is_kinerja_baik];

        AuditService::log('UPDATE', 'Employee', $employee->id, $before, $after, $request);

        return $employee;
    }
}
