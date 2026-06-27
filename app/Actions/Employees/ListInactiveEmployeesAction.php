<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListInactiveEmployeesAction
{
    /**
     * Mengambil pegawai soft-deleted untuk halaman restore dan endpoint API nonaktif.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function execute(array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return Employee::onlyTrashed()
            ->with([
                'jenisPegawai',
                'positionHistories' => fn ($query) => $query
                    ->with('unitKerja')
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan'),
            ])
            ->when($search !== '', function ($query) use ($search): void {
                $search = mb_strtolower($search);
                $query->where(function ($query) use ($search): void {
                    $query
                        ->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(nip) LIKE ?', ["%{$search}%"]);
                });
            })
            ->orderByDesc('deleted_at')
            ->orderBy('nama_lengkap')
            ->paginate($perPage)
            ->withQueryString();
    }
}
