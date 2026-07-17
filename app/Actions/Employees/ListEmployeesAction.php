<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

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
                'foto',
            ])
            ->with([
                'jenisPegawai:id,nama',
                'statusPegawai:id,nama',
                // Semua riwayat dibutuhkan untuk memeriksa kelengkapan SK, bukan
                // hanya riwayat terbaru yang sebelumnya diperlukan oleh tabel.
                'rankHistories:id,employee_id,file_sk',
                'positionHistories' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'file_sk', 'is_latest', 'tmt_jabatan', 'jabatan_id', 'unit_kerja_id'])
                    ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan'),
                'salaryHistories:id,employee_id,file_sk',
                'appointments' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'file_sk', 'tmt_pengangkatan'])
                    ->orderByDesc('tmt_pengangkatan'),
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
        $currentPosition = $p->positionHistories->firstWhere('is_latest', true);
        $statusNama = $p->statusPegawai?->nama ?? $p->status_aktif;
        $tmt = $currentPosition?->tmt_jabatan ?? $p->appointments->first()?->tmt_pengangkatan;

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
            'is_lengkap' => $this->hasCompleteSupportingFiles($p),
            'tmt' => $tmt?->format('d/m/Y'),
        ];
    }

    /**
     * Riwayat yang sudah dibuat wajib memiliki SK yang tersedia di storage.
     * Riwayat yang belum dibuat tidak memengaruhi kelengkapan dokumen pegawai.
     */
    private function hasCompleteSupportingFiles(Employee $employee): bool
    {
        $histories = $employee->rankHistories
            ->concat($employee->positionHistories)
            ->concat($employee->salaryHistories)
            ->concat($employee->appointments);

        if ($histories->isEmpty()) {
            return true;
        }

        $filePaths = $histories->pluck('file_sk');
        if ($filePaths->contains(fn ($path): bool => blank($path))) {
            return false;
        }

        $disk = Storage::disk(Document::STORAGE_DISK);

        return $filePaths
            ->unique()
            ->every(fn (string $path): bool => $disk->exists($path));
    }
}
