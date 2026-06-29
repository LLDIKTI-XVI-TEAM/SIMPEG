<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\SupervisorAssignment;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignSupervisorAction
{
    /**
     * Assign (or clear) the direct supervisor of an employee.
     *
     * Creates a history record in supervisor_assignments and syncs
     * employees.atasan_langsung_id within a single transaction.
     *
     * @throws ValidationException
     */
    public function execute(Employee $employee, ?string $supervisorId, ?Request $request = null): Employee
    {
        if ($supervisorId !== null) {
            if ($employee->id === $supervisorId) {
                throw ValidationException::withMessages([
                    'supervisor_id' => 'Pegawai tidak bisa menjadi atasan untuk diri sendiri.',
                ]);
            }

            $supervisor = Employee::find($supervisorId);
            if (! $supervisor) {
                throw ValidationException::withMessages([
                    'supervisor_id' => 'Atasan yang dipilih tidak ditemukan.',
                ]);
            }
        }

        DB::transaction(function () use ($employee, $supervisorId, $request) {
            $oldValues = $employee->only('atasan_langsung_id');

            // Find current active assignment
            $currentAssignment = SupervisorAssignment::where('employee_id', $employee->id)
                ->whereNull('tanggal_berakhir')
                ->first();

            if ($currentAssignment) {
                // If supervisor is already the same, do nothing
                if ($currentAssignment->supervisor_id === $supervisorId) {
                    return;
                }
                // End current assignment
                $currentAssignment->update([
                    'tanggal_berakhir' => now()->toDateString(),
                ]);
            }

            // Create new assignment if supervisor is set
            if ($supervisorId !== null) {
                SupervisorAssignment::create([
                    'employee_id' => $employee->id,
                    'supervisor_id' => $supervisorId,
                    'tanggal_mulai' => now()->toDateString(),
                    'tanggal_berakhir' => null,
                ]);
            }

            // Sync cached column on employee
            $employee->update([
                'atasan_langsung_id' => $supervisorId,
            ]);

            // Audit log using 'UPDATE' event (matches audit_logs enum)
            AuditService::log(
                'UPDATE',
                'Employee',
                $employee->id,
                $oldValues,
                ['atasan_langsung_id' => $supervisorId],
                $request
            );
        });

        return $employee->load('atasanLangsung');
    }
}
