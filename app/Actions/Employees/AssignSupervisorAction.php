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
     * @throws ValidationException
     */
    public function execute(Employee $employee, ?string $kepalaBagianId, ?Request $request = null): Employee
    {
        if ($kepalaBagianId !== null) {
            if ($employee->id === $kepalaBagianId) {
                throw ValidationException::withMessages([
                    'kepala_bagian_id' => 'Pegawai tidak bisa menjadi kepala bagian untuk diri sendiri.',
                ]);
            }

            $kepalaBagian = Employee::find($kepalaBagianId);
            if (! $kepalaBagian) {
                throw ValidationException::withMessages([
                    'kepala_bagian_id' => 'Kepala bagian yang dipilih tidak ditemukan.',
                ]);
            }
        }

        DB::transaction(function () use ($employee, $kepalaBagianId, $request) {
            $oldValues = $employee->only('kepala_bagian_id');

            $currentAssignment = SupervisorAssignment::where('employee_id', $employee->id)
                ->whereNull('tanggal_berakhir')
                ->first();

            if ($currentAssignment) {
                if ($currentAssignment->kepala_bagian_id === $kepalaBagianId) {
                    return;
                }

                $currentAssignment->update([
                    'tanggal_berakhir' => now()->toDateString(),
                ]);
            }

            if ($kepalaBagianId !== null) {
                SupervisorAssignment::create([
                    'employee_id' => $employee->id,
                    'supervisor_id' => $kepalaBagianId,
                    'kepala_bagian_id' => $kepalaBagianId,
                    'tanggal_mulai' => now()->toDateString(),
                    'tanggal_berakhir' => null,
                ]);
            }

            $employee->update([
                'kepala_bagian_id' => $kepalaBagianId,
            ]);

            AuditService::log(
                'UPDATE',
                'Employee',
                $employee->id,
                $oldValues,
                [
                    'kepala_bagian_id' => $kepalaBagianId,
                ],
                $request
            );
        });

        return $employee->load(['kepalaBagian']);
    }
}
