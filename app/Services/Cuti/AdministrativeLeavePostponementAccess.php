<?php

namespace App\Services\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use Illuminate\Database\Eloquent\Builder;

/** Permission efektif dan scope target dipakai bersama oleh mutasi serta detail tanpa allowlist role. */
final class AdministrativeLeavePostponementAccess
{
    public const PERMISSION = 'cuti.administrative_postponement.manage';

    public function __construct(
        private readonly AnnualLeaveBusinessClock $businessClock,
        private readonly EmployeeDashboardScopeService $employeeScope,
    ) {}

    /** Permission pengelolaan tidak membuka request pegawai lain yang tidak termasuk scope aktor. */
    public function canManage(LeaveRequest $leaveRequest, User $actor): bool
    {
        if (! $actor->hasPermission(self::PERMISSION)) {
            return false;
        }

        // Monitoring dan snapshot approval bukan perluasan scope mutasi administratif.
        return $this->employeeScope->for($actor)->whereKey($leaveRequest->employee_id)->exists();
    }

    /** Pembaca monitoring tidak otomatis menerima alasan bebas yang sensitif. */
    public function canReadReason(LeaveRequest $leaveRequest, User $actor): bool
    {
        return in_array($leaveRequest->id, $this->readableReasonRequestIds([$leaveRequest->id], $actor), true);
    }

    /**
     * Satu halaman audit memakai gate yang sama tanpa query permission/scope per baris.
     * Parent aktual menentukan pemilik; identitas yang tersalin di payload audit bukan authority.
     *
     * @param  list<string>  $requestIds
     * @return list<string>
     */
    public function readableReasonRequestIds(array $requestIds, User $actor): array
    {
        if ($requestIds === []) {
            return [];
        }
        $canManage = $actor->hasPermission(self::PERMISSION);

        return LeaveRequest::query()->whereKey($requestIds)
            ->where(function (Builder $query) use ($actor, $canManage): void {
                $query->where('employee_id', $actor->employee_id)->whereNotNull('employee_id');
                if ($canManage) {
                    $query->orWhereIn('employee_id', $this->employeeScope->for($actor)->select('employees.id'));
                }
            })->pluck('id')->all();
    }

    /** Gate tampilan hanya kelayakan awal; Action memeriksa ulang integritas di bawah lock. */
    public function canPostpone(LeaveRequest $leaveRequest, User $actor): bool
    {
        return $leaveRequest->status === 'disetujui'
            && $leaveRequest->tanggal_mulai !== null
            && $leaveRequest->tanggal_mulai->toDateString() > $this->businessClock->today()->toDateString()
            && $this->canManage($leaveRequest, $actor);
    }
}
