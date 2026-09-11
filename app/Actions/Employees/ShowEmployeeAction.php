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
        $canReadFamilies = (bool) $user?->hasPermission('employee_families.read');
        $canReadHistories = (bool) $user?->hasPermission('employee_histories.read');
        $canReadDiscipline = (bool) $user?->hasPermission('discipline_records.read');
        $canReadDocuments = (bool) $user?->hasPermission('dokumen_sk.read');
        $isSelf = $user?->employee_id !== null && $user->employee_id !== '' && hash_equals((string) $user->employee_id, (string) $employee->id);
        $canReadLeaveRequests = $isSelf
            ? ((bool) $user?->hasPermission('cuti.read_own') || (bool) $user?->hasPermission('cuti.read_all'))
            : (bool) $user?->hasPermission('cuti.read_all');
        $canReadLeaveBalances = (bool) $user?->hasPermission('cuti.balance.read');
        $canReadEwsAlerts = (bool) $user?->hasPermission('ews.read');

        // Bypass saat testing dengan disable auth
        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            $canReadFamilies = $canReadHistories = $canReadDiscipline = $canReadDocuments = true;
            $canReadLeaveRequests = $canReadLeaveBalances = $canReadEwsAlerts = true;
        }

        return $this->payload->response(
            $this->payload->loadRelations($employee, $canReadFamilies, $canReadHistories, $canReadDiscipline, $canReadDocuments, $canReadLeaveRequests, $canReadLeaveBalances, $canReadEwsAlerts),
            $canReadLeaveRequests,
            $canReadLeaveBalances,
            $canReadEwsAlerts
        );
    }
}
