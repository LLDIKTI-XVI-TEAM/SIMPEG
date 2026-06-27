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
            ->when(! empty($filters['golongan']), function ($query) use ($filters): void {
                $query->where(function ($q) use ($filters) {
                    $q->where('golongan_terakhir', $filters['golongan'])
                      ->orWhere('golongan_terakhir', 'LIKE', $filters['golongan'] . '/%');
                });
            })
            ->when(! empty($filters['unit_kerja_id']), function ($query) use ($filters): void {
                $query->whereHas('positionHistories', function ($q) use ($filters) {
                    $q->where('unit_kerja_id', $filters['unit_kerja_id'])
                      ->where('is_latest', true);
                });
            })
            ->when(! empty($filters['jenis_pegawai_id']), function ($query) use ($filters): void {
                $query->where('jenis_pegawai_id', $filters['jenis_pegawai_id']);
            })
            ->orderByDesc('deleted_at')
            ->orderBy('nama_lengkap')
            ->paginate($perPage)
            ->withQueryString();
    }
}
