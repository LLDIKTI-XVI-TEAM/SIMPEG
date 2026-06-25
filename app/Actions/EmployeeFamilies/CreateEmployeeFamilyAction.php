<?php

namespace App\Actions\EmployeeFamilies;

use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Services\AuditService;
use App\Support\EmployeeFamilies\EmployeeFamilyPayload;
use Illuminate\Http\Request;

class CreateEmployeeFamilyAction
{
    public function __construct(private readonly EmployeeFamilyPayload $payload) {}

    /**
     * Membuat data keluarga pegawai dan mencatat audit tanpa NIK.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(Employee $employee, array $data, Request $request): EmployeeFamily
    {
        $family = $employee->families()->create($data);

        AuditService::log(
            'CREATE',
            'EmployeeFamily',
            $family->id,
            null,
            $this->payload->audit($family),
            $request,
        );

        return $family;
    }
}
