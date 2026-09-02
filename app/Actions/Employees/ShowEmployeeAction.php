<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Support\Employees\EmployeeDetailPayload;

class ShowEmployeeAction
{
    public function __construct(private readonly EmployeeDetailPayload $payload) {}

    /**
     * Mengambil payload detail pegawai dengan granular permission gate.
     *
     * @return array<string, mixed>
     */
    public function execute(Employee $employee): array
    {
        $user = request()->user();
        $canReadFamilies = $user?->hasPermission('employee_families.read') || $user?->getEffectiveRole() === 'super_admin';
        $canReadHistories = $user?->hasPermission('employee_histories.read') || $user?->getEffectiveRole() === 'super_admin';
        $canReadDiscipline = $user?->hasPermission('discipline_records.read') || $user?->getEffectiveRole() === 'super_admin';
        $canReadDocuments = $user?->hasPermission('dokumen_sk.read') || $user?->getEffectiveRole() === 'super_admin';

        // Bypass saat testing dengan disable auth
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            $canReadFamilies = $canReadHistories = $canReadDiscipline = $canReadDocuments = true;
        }

        return $this->payload->response($this->payload->loadRelations($employee, $canReadFamilies, $canReadHistories, $canReadDiscipline, $canReadDocuments));
    }
}
