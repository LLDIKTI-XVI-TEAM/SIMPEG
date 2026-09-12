<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\EmployeeDashboardScopeService;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListKepalaBagianLeavesAction
{
    public function __construct(
        private readonly KepalaBagianScopeService $scope,
        private readonly EmployeeDashboardScopeService $employeeScope,
    ) {}

    /** Daftar bawahan memakai hubungan efektif, bukan giliran approver pada snapshot pengajuan. */
    public function execute(User $user, array $filters): LengthAwarePaginator
    {
        abort_unless($user->employee_id !== null && $user->employee?->isActive()
            && $user->hasPermission('cuti.read_all'), 403);

        $perPage = (int) ($filters['per_page'] ?? 10);
        // Assignment bawahan tidak boleh memperluas scope identitas yang hanya berhak membaca diri sendiri.
        $reportIds = $this->scope->directReports($user)
            ->whereIn('employees.id', $this->employeeScope->forIdentity($user)->select('employees.id'))
            ->select('employees.id');

        return LeaveRequest::query()
            ->with(['employee:id,nama_lengkap,nip', 'jenisCuti:id,nama'])
            ->whereIn('employee_id', $reportIds)
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                $query->where('status', $status);
            })
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $keyword = '%'.mb_strtolower(trim($search)).'%';
                $query->whereHas('employee', fn ($employees) => $employees
                    ->where(function ($employees) use ($keyword): void {
                        $employees->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                            ->orWhereRaw('lower(nip) like ?', [$keyword]);
                    }));
            })
            ->when($filters['jenis_cuti_id'] ?? null, fn ($query, string $jenisCutiId) => $query->where('jenis_cuti_id', $jenisCutiId))
            ->when($filters['tahun'] ?? null, fn ($query, int $tahun) => $query->whereYear('tanggal_mulai', $tahun))
            ->when($filters['bulan'] ?? null, fn ($query, int $bulan) => $query->whereMonth('tanggal_mulai', $bulan))
            ->orderBy('tanggal_mulai')
            ->orderBy('id')
            ->paginate(in_array($perPage, [10, 25, 50], true) ? $perPage : 10)
            ->withQueryString();
    }
}
