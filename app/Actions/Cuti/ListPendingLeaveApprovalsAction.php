<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Membangun antrean approval cuti untuk approver tertentu.
 * Kepemilikan langkah aktif (person-based) diselesaikan di query melalui whereHas pada snapshot step,
 * lalu dipaginasi di database dengan urutan deterministik. Tidak ada query per-baris.
 */
class ListPendingLeaveApprovalsAction
{
    public function execute(string $approverEmployeeId, int $perPage = 10): LengthAwarePaginator
    {
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;

        return LeaveRequest::query()
            ->with(['employee', 'jenisCuti', 'steps'])
            ->whereIn('status', ['menunggu_approval', 'ditangguhkan'])
            ->whereHas('steps', fn ($q) => $q
                ->where('status', 'active')
                ->where('approver_employee_id', $approverEmployeeId))
            // Urutan stabil: pengajuan terlama lebih dulu, tie-break dengan id agar deterministik lintas halaman.
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
