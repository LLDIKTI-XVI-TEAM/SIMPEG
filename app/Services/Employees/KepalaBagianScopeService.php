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

        return Employee::query()
            // Hanya pegawai dengan klasifikasi aktif (kelompok referensi) yang masuk
            // scope bawahan; nonaktif tidak lagi muncul meski assignment masih efektif.
            ->whereActiveStatus()
            ->where(function (Builder $query) use ($employeeId): void {
                $query->whereHas('supervisorAssignments', function (Builder $assignments) use ($employeeId): void {
                    $assignments->whereDate('tanggal_mulai', '<=', today()->toDateString())
                        ->where(function (Builder $active): void {
                            $active->whereNull('tanggal_berakhir')
                                ->orWhereDate('tanggal_berakhir', '>=', today()->toDateString());
                        })
                        ->where(function (Builder $assignment) use ($employeeId): void {
                            $assignment->where('kepala_bagian_id', $employeeId)
                                ->orWhere('supervisor_id', $employeeId);
                        });
                })
                    ->orWhere(function (Builder $fallback) use ($employeeId): void {
                        // Pointer lama hanya menjadi fallback bagi pegawai yang belum memiliki histori penugasan.
                        $fallback->where('kepala_bagian_id', $employeeId)
                            ->whereDoesntHave('supervisorAssignments');
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
