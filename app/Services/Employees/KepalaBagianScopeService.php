<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Membatasi data Kepala Bagian hanya pada bawahan langsung yang aktif dipetakan kepadanya.
 */
class KepalaBagianScopeService
{
    /**
     * @return Builder<Employee>
     */
    public function directReports(User $user): Builder
    {
        $employeeId = (string) $user->employee_id;

        if ($employeeId === '') {
            return Employee::query()->whereRaw('1 = 0');
        }

        return Employee::query()->where(function (Builder $query) use ($employeeId): void {
            $query->where('kepala_bagian_id', $employeeId)
                ->orWhereHas('supervisorAssignments', function (Builder $assignments) use ($employeeId): void {
                    $assignments->whereNull('tanggal_berakhir')
                        ->where(function (Builder $assignment) use ($employeeId): void {
                            $assignment->where('kepala_bagian_id', $employeeId)
                                ->orWhere('supervisor_id', $employeeId);
                        });
                });
        });
    }

    /**
     * @return list<string>
     */
    public function directReportIds(User $user): array
    {
        return $this->directReports($user)->pluck('id')->all();
    }

    public function hasDirectReport(User $user, string $employeeId): bool
    {
        return $this->directReports($user)->whereKey($employeeId)->exists();
    }
}
