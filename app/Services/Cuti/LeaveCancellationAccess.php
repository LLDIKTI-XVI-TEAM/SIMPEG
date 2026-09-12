<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/** Menyatukan batas alasan privat dan keputusan pembatalan tanpa mengubah workflow cuti. */
final class LeaveCancellationAccess
{
    public function __construct(private readonly EmployeeDashboardScopeService $employees) {}

    /**
     * Mengiris antrean sebelum pagination agar alasan dan total hanya berasal dari scope aktor asli.
     *
     * @return Builder<Employee>
     */
    public function scope(User $actor): Builder
    {
        if (! $actor->hasPermission('cuti.cancellation.manage') || ! $this->hasActiveBinding($actor)) {
            throw new AuthorizationException('Anda tidak berwenang mengelola permohonan pembatalan cuti.');
        }

        return $this->employees->forIdentity($actor);
    }

    /** Permission monitoring tidak menggantikan izin keputusan atau memperluas scope pemohon. */
    public function canManage(User $actor, LeaveRequest $leave): bool
    {
        return $actor->hasPermission('cuti.cancellation.manage')
            && $this->contains($actor, $leave->employee_id);
    }

    /** Scope ini melengkapi permission; pemanggil tetap wajib memeriksa matrix efektif. */
    public function contains(User $actor, string $employeeId): bool
    {
        return $this->hasActiveBinding($actor)
            && $this->employees->forIdentity($actor)->whereKey($employeeId)->exists();
    }

    /** Binding dan lifecycle dibaca dari database agar perubahan status tidak tertutup relasi lama. */
    private function hasActiveBinding(User $actor): bool
    {
        return $actor->employee_id !== null
            && Employee::query()->whereKey($actor->employee_id)->whereActiveStatus()->exists();
    }
}
