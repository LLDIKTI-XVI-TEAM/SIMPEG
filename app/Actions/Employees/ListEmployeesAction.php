<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Services\EmployeeDocumentStatusService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListEmployeesAction
{
    public function __construct(private readonly EmployeeDocumentStatusService $employeeDocumentStatusService) {}

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

        $employees = Employee::query();

        $showNonaktif = filter_var($validated['show_nonaktif'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($showNonaktif) {
            $employees->onlyTrashed();
        }

        $paginator = $employees
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
                // created_at wajib dimuat agar tie-breaker kanonis (TMT → created_at)
                // konsisten dengan detail/API; tanpa created_at sorting jatuh ke UUID.
                'rankHistories' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'file_sk', 'is_latest', 'tmt_pangkat', 'created_at'])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_pangkat')
                    ->orderByDesc('created_at'),
                'positionHistories' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'file_sk', 'is_latest', 'tmt_jabatan', 'jabatan_id', 'unit_kerja_id', 'created_at'])
                    ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan')
                    ->orderByDesc('created_at'),
                'salaryHistories' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'file_sk', 'is_latest', 'tmt_kgb', 'created_at'])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_kgb')
                    ->orderByDesc('created_at'),
                'appointments' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'file_sk', 'tmt_pengangkatan', 'created_at'])
                    ->orderByDesc('tmt_pengangkatan')
                    ->orderByDesc('created_at'),
                // Berkas lainnya (KTP, KK, mutasi, dll) — hanya ambil field yang dibutuhkan.
                // Metadata arsip ikut dimuat karena dipakai sebagai kandidat nomor/tanggal SK.
                'documents:id,employee_id,jenis_dokumen,file_path,nomor_dokumen,tanggal_dokumen,created_at',
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
                function ($query) use ($validated, $showNonaktif): void {
                    $statusAktif = ($validated['status_aktif'] ?? '') ?: null;

                    // Pilihan status eksplisit selalu dihormati pada kedua mode daftar.
                    if ($statusAktif !== null) {
                        $query->where('status_aktif', $statusAktif);

                        return;
                    }

                    // Default hanya-Aktif adalah aturan daftar pegawai aktif. Pada daftar
                    // pegawai nonaktif, default itu akan menyembunyikan pegawai yang sudah
                    // dinonaktifkan namun berstatus Pensiun, Mutasi, atau Non-Aktif, sehingga
                    // data yang justru dicari lewat filter ini menjadi tidak dapat ditemukan.
                    if (! $showNonaktif) {
                        $query->where('status_aktif', 'Aktif');
                    }
                }
            )
            ->orderBy($sort, $direction)
            ->paginate($perPage);

        // Metadata referensi arsip di-prime satu kali untuk seluruh halaman agar
        // penilaian status kelengkapan tiap baris tidak mengulang query per pegawai.
        $this->employeeDocumentStatusService->primeForEmployees($paginator->getCollection());

        return $paginator
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
            'is_lengkap' => $this->employeeDocumentStatusService->summarize($p)['status_kelengkapan'],
            'tmt' => $tmt?->format('d/m/Y'),
        ];
    }
}
