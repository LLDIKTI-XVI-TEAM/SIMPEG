<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveApprovalService;
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
        $query = LeaveRequest::query()->with([
            'employee',
            'jenisCuti',
            'steps' => fn ($steps) => $steps
                ->select(['id', 'leave_request_id', 'role_label', 'status', 'step_order'])
                ->where('status', 'active')
                ->orderBy('step_order'),
        ]);

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
            if ($filters['status'] === 'menunggu_saya') {
                $query->whereIn('status', LeaveApprovalService::ACTIONABLE_STATUSES);
                if ($user->employee_id) {
                    $query->whereHas('steps', fn ($q) => $q
                        ->where('status', 'active')
                        ->where('approver_employee_id', $user->employee_id));
                } else {
                    $query->whereRaw('1 = 0');
                }
            } elseif ($filters['status'] !== 'all') {
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

        $paginator->getCollection()->transform(function (LeaveRequest $r): LeaveRequest {
            // Label memakai snapshot pengajuan, bukan nama approver atau konfigurasi terkini.
            $r->setAttribute('current_step_label', $r->steps->first()?->role_label);

            return $r;
        });

        return [
            'leaves' => $paginator,
            // Counter memakai predikat actionable yang sama dengan filter menunggu_saya agar angka
            // tidak menghitung pengajuan yang hanya menyimpan step aktif sebagai snapshot.
            'menungguTindakanSaya' => (clone $baseQuery)
                ->whereIn('status', LeaveApprovalService::ACTIONABLE_STATUSES)
                ->whereHas('steps', fn ($q) => $q
                    ->where('status', 'active')
                    ->where('approver_employee_id', $user->employee_id)
                )->count(),
            'totalMenunggu' => (clone $baseQuery)->where('status', 'menunggu_approval')->count(),
            'totalDisetujui' => (clone $baseQuery)->where('status', 'disetujui')->count(),
            'totalDitangguhkan' => (clone $baseQuery)
                ->whereIn('status', ['ditangguhkan', LeaveRequest::STATUS_DUTY_POSTPONED])
                ->count(),
        ];
    }
}
