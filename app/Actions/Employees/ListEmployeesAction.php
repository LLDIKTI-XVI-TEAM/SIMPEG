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
     * @return LengthAwarePaginator<int, array<string, mixed>>
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
                'golongan_terakhir',
                'jabatan_terakhir',
                'jenis_pegawai_id',
                'status_pegawai_id',
                'status_aktif',
                'profil_status',
                'foto',
            ])
            ->with([
                'jenisPegawai:id,nama',
                'statusPegawai:id,nama',
                'positionHistories' => fn ($q) => $q
                    ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                    ->where('is_latest', true)
                    ->orderByDesc('tmt_jabatan')
                    ->limit(1),
                'appointment:id,employee_id,tmt_pengangkatan',
            ])
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
                $validated['unit_kerja_id'] ?? null,
                fn ($query, string $unitKerjaId) => $query->whereHas('positionHistories', function ($q) use ($unitKerjaId): void {
                    $q->where('unit_kerja_id', $unitKerjaId)->where('is_latest', true);
                })
            )
            ->when(
                $validated['jenis_pegawai_id'] ?? null,
                fn ($query, string $jenisPegawaiId) => $query->where('jenis_pegawai_id', $jenisPegawaiId)
            )
            ->when(
                ($validated['status_pegawai_id'] ?? null) ?: null,
                function ($query, string $statusPegawaiId) {
                    if ($statusPegawaiId !== 'all') {
                        $query->where('status_pegawai_id', $statusPegawaiId);
                    }
                },
                fn ($query) => $query->where('status_aktif', ($validated['status_aktif'] ?? '') ?: 'Aktif')
            )
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Employee $p) => $this->toTableRow($p));
    }

    /**
     * Mengubah model Employee menjadi flat array yang siap dikonsumsi Alpine.js di tabel admin.
     *
     * @return array<string, mixed>
     */
    public function toTableRow(Employee $p): array
    {
        $currentPosition = $p->positionHistories->first();
        $statusNama = $p->statusPegawai?->nama ?? $p->status_aktif;
        $tmt = $currentPosition?->tmt_jabatan ?? $p->appointment?->tmt_pengangkatan;

        return [
            'id' => $p->id,
            'nama_lengkap' => $p->nama_lengkap,
            'nip' => $p->nip,
            'foto_url' => $p->foto_url,
            'jabatan' => $p->jabatan_terakhir ?: '-',
            'unit_kerja' => $currentPosition?->unitKerja?->nama ?? '-',
            'golongan_terakhir' => $p->golongan_terakhir ?? '-',
            'jenis_pegawai' => $p->jenisPegawai?->nama ?? '-',
            'status_nama' => $statusNama,
            'status_key' => strtolower((string) $statusNama),
            'is_lengkap' => $p->profil_status === 'lengkap',
            'tmt' => $tmt?->format('d/m/Y'),
        ];
    }
}
