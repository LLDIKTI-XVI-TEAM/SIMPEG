<?php

namespace App\Actions\EmployeeFamilies;

use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Services\AuditService;
use App\Support\EmployeeFamilies\EmployeeFamilyPayload;
use Illuminate\Http\Request;

class UpdateEmployeeFamilyAction
{
    public function __construct(private readonly EmployeeFamilyPayload $payload) {}

    /**
     * Memperbarui keluarga hanya jika record tersebut milik pegawai pada route.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, EmployeeFamily $family, array $data, Request $request): EmployeeFamily
    {
        $this->abortIfFamilyOutsideEmployee($employee, $family);

        $oldValues = $this->payload->audit($family);

        $family->update($data);

        AuditService::log(
            'UPDATE',
            'EmployeeFamily',
            $family->id,
            $oldValues,
            $this->payload->audit($family),
            $request,
        );

        return $family;
    }

    private function abortIfFamilyOutsideEmployee(Employee $employee, EmployeeFamily $family): void
    {
        abort_unless($family->employee_id === $employee->id, 404);
    }
}
