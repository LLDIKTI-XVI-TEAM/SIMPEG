<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeDocumentStatusService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListEmployeesAction
{
    public function __construct(
        private readonly EmployeeDocumentStatusService $documentStatus,
    ) {}

    /**
     * Mengambil daftar pegawai dengan filter default hanya pegawai aktif.
     *
     * Data milik sendiri (user.employee_id) selalu dikecualikan kecuali untuk
     * Super Admin efektif — pengelolaan data sendiri wajib lewat Profil Saya
     * (employees.read_self), bukan dari daftar. Diterapkan sebelum paginasi
     * agar total/meta konsisten.
     *
     * @param  array<string, mixed>  $validated
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function execute(array $validated, ?User $viewer = null): LengthAwarePaginator
    {
        $sort = $validated['sort'] ?? 'nama_lengkap';
        $direction = $validated['direction'] ?? 'asc';
        $perPage = (int) ($validated['per_page'] ?? 10);

        // Soft delete sudah dihapus (keputusan produk): parameter show_nonaktif/onlyTrashed
        // tidak lagi relevan — nonaktif kini status kepegawaian biasa.
        $employees = Employee::query();

        if ($viewer !== null
            && $viewer->getEffectiveRole() !== 'super_admin'
            && is_string($viewer->employee_id)
            && $viewer->employee_id !== '') {
            $employees->whereKeyNot($viewer->employee_id);
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
                // kelompok dibutuhkan isActive() untuk klasifikasi aktif/nonaktif.
                'statusPegawai:id,nama,kelompok',
                // Semua riwayat dibutuhkan untuk memeriksa kelengkapan SK, bukan
                // hanya riwayat terbaru yang sebelumnya diperlukan oleh tabel.
                'rankHistories:id,employee_id,no_sk,tanggal_sk,tmt_pangkat,file_sk,is_latest,created_at',
                'positionHistories' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'no_sk', 'tanggal_sk', 'file_sk', 'is_latest', 'tmt_jabatan', 'jabatan_id', 'unit_kerja_id', 'created_at'])
                    ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan'),
                'salaryHistories:id,employee_id,no_sk,tanggal_sk,tmt_kgb,file_sk,is_latest,created_at',
                'appointments' => fn ($query) => $query
                    ->select(['id', 'employee_id', 'no_sk', 'tanggal_sk', 'file_sk', 'tmt_pengangkatan', 'created_at'])
                    ->orderByDesc('tmt_pengangkatan')
                    ->orderByDesc('created_at'),
                'documents:id,employee_id,jenis_dokumen,nama_dokumen,nomor_dokumen,tanggal_dokumen,file_path,keterangan,created_at',
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
                ($statusPegawaiId = ($validated['status_pegawai_id'] ?? null)) && $statusPegawaiId !== 'all'
                    ? $statusPegawaiId
                    : null,
                function ($query, string $statusPegawaiId): void {
                    $query->where('status_pegawai_id', $statusPegawaiId);
                },
                function ($query) use ($validated): void {
                    $statusAktif = ($validated['status_aktif'] ?? '') ?: null;

                    // Pilihan status eksplisit selalu dihormati.
                    if ($statusAktif !== null) {
                        $query->where('status_aktif', $statusAktif);

                        return;
                    }

                    // Nilai "all" (atau tanpa filter status) diperlakukan seperti
                    // tidak ada filter spesifik: default hanya menampilkan pegawai
                    // aktif menurut klasifikasi kelompok referensi — satu sumber
                    // dengan ekspor dan isActive(). Pegawai nonaktif hanya terlihat
                    // melalui filter status.
                    $query->whereActiveStatus();
                }
            )
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();

        $this->documentStatus->primeForEmployees($paginator->getCollection());

        return $paginator->through(fn (Employee $p) => $this->toTableRow($p));
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

        $documentStatus = $this->documentStatus->summarize($p);

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
            // Klasifikasi aktif dari kelompok status (satu sumber dengan isActive()),
            // bukan nama snapshot: Tugas Belajar (Aktif/khusus) tetap terhitung aktif.
            'is_aktif' => $p->isActive(),
            // Key is_lengkap dipertahankan karena sudah menjadi kontrak tabel;
            // nilainya adalah key status kelengkapan dokumen dari service development.
            'is_lengkap' => $documentStatus['status_kelengkapan'],
            'dokumen_is_dinilai' => $documentStatus['is_dinilai'],
            'dokumen_tersedia_count' => $documentStatus['tersedia_count'],
            'dokumen_total_wajib' => $documentStatus['total_wajib'],
            'tmt' => $tmt?->format('d/m/Y'),
        ];
    }
}
