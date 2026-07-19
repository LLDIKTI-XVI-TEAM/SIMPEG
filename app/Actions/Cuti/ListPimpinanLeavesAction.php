<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListPimpinanLeavesAction
{
    /**
     * @return array{
     *   leaves: LengthAwarePaginator,
     *   menungguTindakanSaya: int, totalMenunggu: int, totalDisetujui: int, totalDitangguhkan: int
     * }
     */
    public function execute(User $user, array $filters): array
    {
        $query = LeaveRequest::query()->with(['employee', 'jenisCuti', 'steps']);

        // Base query untuk counter statistik
        $baseQuery = clone $query;

        if (filled($filters['search'] ?? null)) {
            $search = '%'.trim((string) $filters['search']).'%';
            $query->whereHas('employee', fn ($employees) => $employees
                ->where('nama_lengkap', 'like', $search)
                ->orWhere('nip', 'like', $search));
        }
        if (filled($filters['unit_kerja_id'] ?? null)) {
            $query->whereHas('employee.positionHistories', fn ($positions) => $positions
                ->where('is_latest', true)
                ->where('unit_kerja_id', $filters['unit_kerja_id']));
        }
        if (filled($filters['periode'] ?? null)) {
            $parts = explode('-', $filters['periode']);
            if (count($parts) === 2) {
                $query->whereYear('tanggal_mulai', $parts[0])->whereMonth('tanggal_mulai', $parts[1]);
            }
        }
        if (filled($filters['jenis_cuti_id'] ?? null)) {
            $query->where('jenis_cuti_id', $filters['jenis_cuti_id']);
        }
        if (filled($filters['status'] ?? null)) {
            if ($filters['status'] === 'menunggu_saya' && $user->employee_id) {
                $query->whereHas('steps', fn ($q) => $q
                    ->where('status', 'active')
                    ->where('approver_employee_id', $user->employee_id));
            } else {
                $statuses = match ($filters['status']) {
                    'menunggu' => ['menunggu_approval'],
                    'perubahan' => ['perlu_perubahan'],
                    default => [$filters['status']],
                };
                $query->whereIn('status', $statuses);
            }
        }

        $perPage = (int) request('per_page', 10);
        $paginator = $query->latest()->paginate($perPage)->withQueryString();

        // Pimpinan needs activeStep for the table
        $paginator->getCollection()->transform(function (LeaveRequest $r) {
            $r->activeStep = $r->steps->firstWhere('status', 'active');

            return $r;
        });

        return [
            'leaves' => $paginator,
            'menungguTindakanSaya' => (clone $baseQuery)->whereHas('steps', fn ($q) => $q
                ->where('status', 'active')
                ->where('approver_employee_id', $user->employee_id)
            )->count(),
            'totalMenunggu' => (clone $baseQuery)->where('status', 'menunggu_approval')->count(),
            'totalDisetujui' => (clone $baseQuery)->where('status', 'disetujui')->count(),
            'totalDitangguhkan' => (clone $baseQuery)->where('status', 'ditangguhkan')->count(),
        ];
    }
}
