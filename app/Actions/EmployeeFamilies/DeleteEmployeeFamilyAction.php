<?php

namespace App\Actions\EmployeeFamilies;

use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Services\AuditService;
use App\Support\EmployeeFamilies\EmployeeFamilyPayload;
use Illuminate\Http\Request;

class DeleteEmployeeFamilyAction
{
    public function __construct(private readonly EmployeeFamilyPayload $payload) {}

    /**
     * Menghapus data keluarga secara permanen dan mencatat audit tanpa NIK.
     */
    public function execute(Employee $employee, EmployeeFamily $family, Request $request): void
    {
        $this->abortIfFamilyOutsideEmployee($employee, $family);

        $oldValues = $this->payload->audit($family);
        $familyId = $family->id;

        $family->forceDelete();

        AuditService::log(
            'DELETE',
            'EmployeeFamily',
            $familyId,
            $oldValues,
            null,
            $request,
        );
    }

    private function abortIfFamilyOutsideEmployee(Employee $employee, EmployeeFamily $family): void
    {
        abort_unless($family->employee_id === $employee->id, 404);
    }
}
