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
        return $actor === null
            ? $this->none()
            : $this->forRole($actor, $actor->getEffectiveRole());
    }

    /**
     * Menentukan scope dari role asli agar Switch Role tidak mengganti identitas,
     * ownership, atau himpunan pegawai yang boleh dijangkau aktor.
     *
     * @return Builder<Employee>
     */
    public function forIdentity(?User $actor): Builder
    {
        return $actor === null
            ? $this->none()
            : $this->forRole($actor, $actor->role);
    }

    /**
     * Menandai identitas dengan scope organisasi global tanpa menjadikannya
     * pengganti pemeriksaan permission efektif pada capability terkait.
     */
    public function hasGlobalIdentityScope(?User $actor): bool
    {
        return $actor !== null
            && in_array($actor->role, ['super_admin', 'admin_kepegawaian', 'pimpinan'], true);
    }

    /** @return Builder<Employee> */
    private function forRole(User $actor, ?string $role): Builder
    {
        return match ($role) {
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
