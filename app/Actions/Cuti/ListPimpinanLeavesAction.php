<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListPimpinanLeavesAction
{
    public function execute(array $filters): LengthAwarePaginator
    {
        $query = LeaveRequest::query()->with(['employee', 'jenisCuti']);

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
        if (filled($filters['tahun'] ?? null)) {
            $query->whereYear('tanggal_mulai', $filters['tahun']);
        }
        if (filled($filters['bulan'] ?? null)) {
            $query->whereMonth('tanggal_mulai', $filters['bulan']);
        }
        if (filled($filters['jenis_cuti_id'] ?? null)) {
            $query->where('jenis_cuti_id', $filters['jenis_cuti_id']);
        }
        if (filled($filters['status'] ?? null)) {
            $statuses = match ($filters['status']) {
                'menunggu' => ['menunggu_approval'],
                'perubahan' => ['perlu_perubahan'],
                default => [$filters['status']],
            };
            $query->whereIn('status', $statuses);
        }

        return $query->latest()->paginate(20)->withQueryString();
    }
}
