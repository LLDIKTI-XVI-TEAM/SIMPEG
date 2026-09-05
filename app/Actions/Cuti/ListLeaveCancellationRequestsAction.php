<?php

namespace App\Actions\Cuti;

use App\Models\LeaveCancellationRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Menyediakan antrean pembatalan Admin dengan relasi terikat dan urutan stabil lintas halaman. */
final class ListLeaveCancellationRequestsAction
{
    /** @param array{status?: string, per_page?: int} $filters */
    public function execute(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 10);
        $status = $filters['status'] ?? LeaveCancellationRequest::STATUS_PENDING;

        return LeaveCancellationRequest::query()
            ->with([
                'leaveRequest:id,employee_id,jenis_cuti_id,tanggal_mulai,tanggal_selesai',
                'leaveRequest.employee:id,nama_lengkap,nip',
                'leaveRequest.jenisCuti:id,nama',
            ])
            ->when(
                $status !== 'all',
                fn ($query) => $query->where('status', $status),
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
