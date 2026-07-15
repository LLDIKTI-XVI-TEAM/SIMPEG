<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;

class ShowKepalaBagianEmployeeAction
{
    public function __construct(private readonly KepalaBagianScopeService $scope) {}

    public function execute(User $user, Employee $employee): Employee
    {
        abort_unless($this->scope->hasDirectReport($user, $employee->id), 403);

        return $employee->load([
            'jenisPegawai:id,nama',
            'statusPegawai:id,nama',
            'positionHistories' => fn ($query) => $query
                ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                ->where('is_latest', true)
                ->orderByDesc('tmt_jabatan')
                ->limit(1),
            'leaveRequests' => fn ($query) => $query
                ->with('jenisCuti:id,nama')
                ->latest()
                ->limit(5),
            'ewsAlerts' => fn ($query) => $query
                ->where('followup_status', 'aktif')
                ->orderBy('target_date')
                ->limit(5),
        ]);
    }
}
