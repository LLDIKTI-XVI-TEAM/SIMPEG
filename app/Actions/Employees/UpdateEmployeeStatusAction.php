<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateEmployeeStatusAction
{
    /**
     * Mengubah status pegawai.
     */
    public function execute(Employee $employee, string $statusName, Request $request): Employee
    {
        $status = RefStatusPegawai::where('nama', $statusName)->first();

        if (! $status) {
            throw new InvalidArgumentException("Status pegawai {$statusName} tidak ditemukan.");
        }

        return DB::transaction(function () use ($employee, $status, $request) {
            $oldValues = $employee->getRawOriginal();

            $employee->update([
                'status_pegawai_id' => $status->id,
            ]);

            AuditService::log('UPDATE_STATUS', 'Employee', $employee->id, $oldValues, $employee->getAttributes(), $request);

            return $employee->fresh();
        });
    }
}
