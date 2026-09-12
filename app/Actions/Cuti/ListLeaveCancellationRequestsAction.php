<?php

namespace App\Actions\Cuti;

use App\Models\LeaveCancellationRequest;
use App\Models\User;
use App\Services\Cuti\LeaveCancellationAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** Menyediakan antrean pembatalan berizin dengan relasi terikat dan urutan stabil lintas halaman. */
final class ListLeaveCancellationRequestsAction
{
    public function __construct(private readonly LeaveCancellationAccess $access) {}

    /** @param array{status?: string, per_page?: int} $filters */
    public function execute(User $actor, array $filters): LengthAwarePaginator
    {
        $employees = $this->access->scope($actor)->select('employees.id');
        $perPage = (int) ($filters['per_page'] ?? 10);
        $status = $filters['status'] ?? LeaveCancellationRequest::STATUS_PENDING;

        return LeaveCancellationRequest::query()
            ->whereHas('leaveRequest', fn ($query) => $query->whereIn('employee_id', $employees))
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
