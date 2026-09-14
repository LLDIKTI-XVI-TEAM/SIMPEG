<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Support\Employees\EmployeeDetailPayload;

class ShowMyProfileAction
{
    public function __construct(private readonly EmployeeDetailPayload $payload) {}

    /**
     * Mengambil profil pegawai milik user login, fail-closed jika belum terhubung.
     *
     * @return array<string, mixed>
     */
    public function execute(?Employee $employee): array
    {
        abort_if($employee === null, 404, 'Data pegawai untuk akun ini belum terhubung.');

        $user = request()->user();
        $canReadLeaveRequests = $user?->hasPermission('cuti.read_own') || $user?->hasPermission('cuti.read_all');
        $canReadLeaveBalances = $user?->hasPermission('cuti.balance.read');
        $canReadEwsAlerts = $user?->hasPermission('ews.read');

        if (app()->environment('local') && config('services.simpeg.disable_employee_api_auth')) {
            $canReadLeaveRequests = $canReadLeaveBalances = $canReadEwsAlerts = true;
        }

        return $this->payload->response(
            $this->payload->loadRelations($employee, true, true, true, true, $canReadLeaveRequests, $canReadLeaveBalances, $canReadEwsAlerts),
            $canReadLeaveRequests,
            $canReadLeaveBalances,
            $canReadEwsAlerts
        );
    }
}
