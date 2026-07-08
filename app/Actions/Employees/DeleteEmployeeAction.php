<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeleteEmployeeAction
{
    /**
     * Menghapus pegawai secara permanen (hard delete).
     * Ini akan menghapus semua riwayat dan relasi yang berkaitan melalui ON DELETE CASCADE (jika ada).
     */
    public function execute(Employee $employee, Request $request): void
    {
        DB::transaction(function () use ($employee, $request): void {
            $oldValues = $employee->getRawOriginal();
            $employeeId = $employee->id;

            $employee->forceDelete();

            AuditService::log('HARD_DELETE', 'Employee', $employeeId, $oldValues, null, $request);
        });
    }
}
