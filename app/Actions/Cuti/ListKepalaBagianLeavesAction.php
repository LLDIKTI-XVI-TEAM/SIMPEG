<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListKepalaBagianLeavesAction
{
    public function __construct(private readonly KepalaBagianScopeService $scope) {}

    public function execute(User $user, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 10);
        $reportIds = $this->scope->directReports($user)->select('employees.id');

        return LeaveRequest::query()
            ->with(['employee:id,nama_lengkap,nip', 'jenisCuti:id,nama'])
            ->whereIn('employee_id', $reportIds)
            ->when($filters['status'] ?? null, function (Builder $query, string $status) use ($user): void {
                $query->where('status', $status);

                if ($status === 'menunggu_approval') {
                    // Queue keputusan hanya memuat tahap aktif yang memang ditugaskan kepada actor.
                    $query->whereHas('steps', fn (Builder $steps): Builder => $steps
                        ->where('status', 'active')
                        ->where('approver_employee_id', $user->employee_id));
                }
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
            ->paginate(in_array($perPage, [10, 25, 50], true) ? $perPage : 10)
            ->withQueryString();
    }
}
