<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListEmployeesAction
{
    /**
     * Mengambil daftar pegawai dengan filter default hanya pegawai aktif.
     *
     * @param  array<string, mixed>  $validated
     * @return LengthAwarePaginator<int, Employee>
     */
    public function execute(array $validated): LengthAwarePaginator
    {
        $sort = $validated['sort'] ?? 'nama_lengkap';
        $direction = $validated['direction'] ?? 'asc';
        $perPage = (int) ($validated['per_page'] ?? 10);

        return Employee::query()
            ->select([
                'id',
                'nama_lengkap',
                'nip',
                'email',
                'golongan_terakhir',
                'pangkat_terakhir',
                'jabatan_terakhir',
                'kelas_jabatan',
                'jenis_pegawai_id',
                'status_aktif',
                'foto',
                'created_at',
            ])
            ->with(['jenisPegawai:id,nama'])
            ->when(
                $validated['search'] ?? null,
                fn ($query, string $search) => $query->where(function ($query) use ($search): void {
                    $keyword = '%'.mb_strtolower($search).'%';

                    $query->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                        ->orWhereRaw('lower(nip) like ?', [$keyword]);
                })
            )
            ->when(
                $validated['golongan'] ?? null,
                fn ($query, string $golongan) => $query->where(function ($q) use ($golongan) {
                    $q->where('golongan_terakhir', $golongan)
                        ->orWhere('golongan_terakhir', 'LIKE', $golongan.'/%');
                })
            )
            ->when(
                $validated['jenis_pegawai_id'] ?? null,
                fn ($query, string $jenisPegawaiId) => $query->where('jenis_pegawai_id', $jenisPegawaiId)
            )
            ->when(
                $validated['status_aktif'] ?? null,
                fn ($query, string $statusAktif) => $query->where('status_aktif', $statusAktif),
                fn ($query) => $query->where('status_aktif', 'Aktif')
            )
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();
    }
}
