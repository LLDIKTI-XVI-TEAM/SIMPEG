<?php

namespace App\Services\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;

/** Menyatukan akses record tanpa menyamakan hak baca, hak unduh, dan hak mengambil keputusan. */
final class LeaveRequestReadAccess
{
    public function __construct(
        private readonly EmployeeDashboardScopeService $employeeScope,
        private readonly AdministrativeLeavePostponementAccess $administrativeAccess,
        private readonly LeaveCancellationAccess $cancellations,
    ) {}

    /** Pengelola hanya mendapat konteks pembatalan yang tercatat, bukan monitoring atau unduhan privat. */
    public function canReadDetail(LeaveRequest $leave, ?User $actor): bool
    {
        return $actor !== null && $this->hasActiveIdentity($actor)
            && ($this->canRead($leave, $actor)
                || $this->administrativeAccess->canManage($leave, $actor)
                || ($this->cancellations->canManage($actor, $leave) && $leave->cancellationRequests()->exists()));
    }

    /** Snapshot memberi akses record terkait saja, termasuk sebelum dan sesudah giliran persetujuan. */
    public function canRead(LeaveRequest $leave, ?User $actor): bool
    {
        if ($actor === null || ! $this->hasActiveIdentity($actor)) {
            return false;
        }

        if ($leave->employee_id === $actor->employee_id) {
            return true;
        }

        $leave->loadMissing('steps:id,leave_request_id,approver_employee_id');

        return $leave->steps->contains('approver_employee_id', $actor->employee_id)
            || $this->canMonitor($leave, $actor);
    }

    /** Permission efektif tidak memperluas himpunan pegawai milik identitas asli saat Switch Role. */
    public function canMonitor(LeaveRequest $leave, ?User $actor): bool
    {
        return $actor !== null && $this->hasActiveIdentity($actor)
            && $actor->hasPermission('cuti.read_all')
            && $this->employeeScope->forIdentity($actor)->whereKey($leave->employee_id)->exists();
    }

    /** Izin saldo berdiri sendiri; hak baca snapshot tidak memperluas scope saldo pegawai. */
    public function canReadBalance(LeaveRequest $leave, User $actor): bool
    {
        return $this->hasActiveIdentity($actor)
            && $actor->hasPermission('cuti.balance.read')
            && $this->employeeScope->forIdentity($actor)->whereKey($leave->employee_id)->exists();
    }

    /** Binding kosong atau pegawai nonaktif tidak boleh cocok dengan snapshot approver kosong. */
    private function hasActiveIdentity(?User $actor): bool
    {
        return $actor?->employee_id !== null && $actor?->employee?->isActive() === true;
    }
}
