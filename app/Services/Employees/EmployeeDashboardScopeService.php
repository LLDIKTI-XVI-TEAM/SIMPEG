<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Menyediakan scope pegawai yang sama dengan dashboard milik aktor.
 *
 * Permission efektif menentukan boleh atau tidaknya suatu fitur dibuka. Scope
 * tetap berasal dari identitas aktor. Role efektif hanya menentukan bentuk
 * scope dashboard agar simulasi mereproduksi hak target tanpa impersonasi,
 * sesuai K-RBAC-02.
 */
class EmployeeDashboardScopeService
{
    public function __construct(private readonly KepalaBagianScopeService $kepalaBagianScope) {}

    /** @return Builder<Employee> */
    public function for(?User $actor): Builder
    {
        if ($actor === null) {
            return $this->none();
        }

        return match ($actor->getEffectiveRole()) {
            'super_admin', 'admin_kepegawaian', 'pimpinan' => Employee::query(),
            'kepala_bagian' => $this->kepalaBagianScope->directReports($actor),
            'pegawai' => $this->ownEmployee($actor),
            default => $this->none(),
        };
    }

    /** @return Builder<Employee> */
    private function ownEmployee(User $actor): Builder
    {
        $employeeId = trim((string) $actor->employee_id);

        return $employeeId === ''
            ? $this->none()
            : Employee::query()->whereKey($employeeId);
    }

    /** @return Builder<Employee> */
    private function none(): Builder
    {
        return Employee::query()->whereRaw('1 = 0');
    }
}
