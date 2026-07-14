<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListKepalaBagianLeavesAction
{
    public function __construct(private readonly KepalaBagianScopeService $scope) {}

    public function execute(User $user, array $filters): LengthAwarePaginator
    {
        $reportIds = $this->scope->directReportIds($user);

        return LeaveRequest::query()
            ->with(['employee:id,nama_lengkap,nip', 'jenisCuti:id,nama'])
            ->whereIn('employee_id', $reportIds)
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
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
            ->paginate(20)
            ->withQueryString();
    }
}
