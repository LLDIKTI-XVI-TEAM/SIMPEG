<?php

namespace App\Services;

use App\Models\Employee;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use Illuminate\Support\Collection;

/**
 * Menilai kelengkapan dokumen SK pegawai berdasarkan riwayat kanonis, arsip
 * dokumen, dan file fisik pada storage privat.
 *
 * Validasi ketersediaan file memakai EmployeeHistoryAttachmentService sehingga
 * status kelengkapan dan unduhan terotorisasi bersumber dari keputusan yang
 * sama: kepemilikan pegawai, kategori dokumen, dan keberadaan file fisik dicek
 * secara fail-closed terhadap metadata yang saling bertentangan.
 */
class EmployeeDocumentStatusService
{
    /** @var array<string, string> */
    private const REQUIRED_SK = [
        'sk_pengangkatan' => 'SK Pengangkatan',
        'sk_pangkat' => 'SK Pangkat',
        'sk_jabatan' => 'SK Jabatan',
        'sk_kgb' => 'SK KGB',
    ];

    /** @var array<string, string> */
    private const ATTACHMENT_TYPES = [
        'sk_pengangkatan' => 'appointment',
        'sk_pangkat' => 'rank',
        'sk_jabatan' => 'position',
        'sk_kgb' => 'salary',
    ];

    public function __construct(
        private readonly EmployeeHistoryAttachmentService $attachments,
    ) {}

    /**
     * Prime metadata referensi dokumen untuk banyak pegawai sekaligus. Dipanggil
     * pada halaman daftar sebelum baris dipetakan agar pemeriksaan status tiap
     * baris tidak mengulang query metadata per pegawai.
     *
     * @param  iterable<int, Employee>  $employees  Pegawai dengan relasi riwayat/arsip sudah dimuat.
     */
    public function primeForEmployees(iterable $employees): void
    {
        $paths = [];

        foreach ($employees as $employee) {
            foreach (['appointments', 'rankHistories', 'positionHistories', 'salaryHistories'] as $relation) {
                foreach ($employee->{$relation} as $history) {
                    if (is_string($history->file_sk) && $history->file_sk !== '') {
                        $paths[] = $history->file_sk;
                    }
                }
            }

            foreach ($employee->documents as $document) {
                if (is_string($document->file_path) && $document->file_path !== '') {
                    $paths[] = $document->file_path;
                }
            }
        }

        $this->attachments->primeDocumentReferences($paths);
    }

    /**
     * @return array{
     *     status_kelengkapan: string,
     *     is_lengkap: bool,
     *     total_wajib: int,
     *     tersedia_count: int,
     *     belum_ada_count: int,
     *     perlu_perbaikan_count: int,
     *     required_sks: list<array<string, mixed>>
     * }
     */
    public function summarize(Employee $employee): array
    {
        // Riwayat dikirim dalam urutan kanonis: terbaru di depan. Evaluasi status
        // selalu mengacu pada riwayat paling mutakhir sehingga kerusakan pada
        // riwayat terbaru tidak tertutup oleh riwayat lama yang masih valid.
        $sources = [
            'sk_pengangkatan' => $this->historySources(
                $employee->appointments->sortByDesc('tmt_pengangkatan')->values()
            ),
            'sk_pangkat' => $this->historySources(
                $employee->rankHistories->sortByDesc('tmt_pangkat')->sortByDesc('is_latest')->values()
            ),
            'sk_jabatan' => $this->historySources(
                $employee->positionHistories->sortByDesc('tmt_jabatan')->sortByDesc('is_latest')->values()
            ),
            'sk_kgb' => $this->historySources(
                $employee->salaryHistories->sortByDesc('tmt_kgb')->sortByDesc('is_latest')->values()
            ),
        ];

        foreach ($employee->documents->sortByDesc('created_at')->values() as $document) {
            if (! array_key_exists($document->jenis_dokumen, self::REQUIRED_SK)) {
                continue;
            }

            $sources[$document->jenis_dokumen][] = [
                'file_path' => $document->file_path,
                'source' => 'Arsip Dokumen',
                'nomor_sk' => $document->nomor_dokumen,
                'tanggal_sk' => $document->tanggal_dokumen?->toDateString(),
                'document' => $document,
            ];
        }

        // Metadata referensi dokumen di-prime satu kali per pegawai agar pemeriksaan
        // scoped validator untuk banyak kandidat tidak memicu query per file.
        $this->attachments->primeDocumentReferences(
            collect($sources)->flatten(1)->pluck('file_path')
        );

        $requiredSks = [];
        $tersediaCount = 0;
        $belumAdaCount = 0;
        $perluPerbaikanCount = 0;

        foreach (self::REQUIRED_SK as $key => $label) {
            $candidates = collect($sources[$key]);

            // Kandidat pertama dari riwayat adalah kandidat kanonis. Jika file-nya
            // tidak lolos scoped validator, kategori langsung perlu_perbaikan tanpa
            // memeriksa kandidat lain; arsip dokumen tidak boleh menutupi kerusakan
            // referensi resmi pada riwayat.
            $canonical = $candidates->first();
            $canonicalBroken = $canonical !== null
                && $canonical['source'] === 'Riwayat Pegawai'
                && $this->scopedValidPath($employee, $key, $canonical) === null;

            $available = $canonicalBroken
                ? null
                : $candidates->first(
                    fn (array $candidate): bool => $this->scopedValidPath($employee, $key, $candidate) !== null
                );

            if ($available !== null) {
                $state = 'tersedia';
                $statusLabel = 'File tersedia';
                $tersediaCount++;
            } elseif ($candidates->isEmpty()) {
                $state = 'belum_ada';
                $statusLabel = 'Belum ada data SK';
                $belumAdaCount++;
            } else {
                $state = 'perlu_perbaikan';
                $statusLabel = blank($canonical['file_path'] ?? null)
                    ? 'Berkas belum diunggah'
                    : 'File tidak ditemukan di storage';
                $perluPerbaikanCount++;
            }

            $metadataSource = $candidates->first();
            $requiredSks[] = [
                'jenis' => $key,
                'label' => $label,
                'status' => $state,
                'status_label' => $statusLabel,
                'file_path' => $available['file_path'] ?? null,
                'file_url' => $this->resolveFileUrl($employee, $key, $available),
                'nomor_sk' => $metadataSource['nomor_sk'] ?? null,
                'tanggal_sk' => $metadataSource['tanggal_sk'] ?? null,
                'sources_count' => $candidates->count(),
            ];
        }

        $totalWajib = count(self::REQUIRED_SK);
        $statusKelengkapan = match (true) {
            $perluPerbaikanCount > 0 => 'perlu_perbaikan',
            $tersediaCount === $totalWajib => 'lengkap',
            $belumAdaCount === $totalWajib => 'belum_ada',
            default => 'belum_lengkap',
        };

        return [
            'status_kelengkapan' => $statusKelengkapan,
            'is_lengkap' => $statusKelengkapan === 'lengkap',
            'total_wajib' => $totalWajib,
            'tersedia_count' => $tersediaCount,
            'belum_ada_count' => $belumAdaCount,
            'perlu_perbaikan_count' => $perluPerbaikanCount,
            'required_sks' => $requiredSks,
        ];
    }

    /**
     * URL unduh terotorisasi — bukan path publik /storage. Route unduh tetap
     * memagari akses (role/permission + verifikasi kepemilikan/file di controller)
     * sehingga pembentukan URL di sini tetap fail-closed.
     *
     * @param  array<string, mixed>|null  $candidate
     */
    private function resolveFileUrl(Employee $employee, string $requiredSkKey, ?array $candidate): ?string
    {
        if ($candidate === null || blank($candidate['file_path'] ?? null)) {
            return null;
        }

        if (array_key_exists('document', $candidate)) {
            return route('dokumen.download', $candidate['document']);
        }

        return route('pegawai.history-attachments.download', [
            'employee' => $employee,
            'type' => self::ATTACHMENT_TYPES[$requiredSkKey],
            'history' => $candidate['history'],
        ]);
    }

    /**
     * Memeriksa kandidat melalui scoped validator yang sama dengan unduhan
     * terotorisasi sehingga status tidak pernah menampilkan link yang akan
     * berakhir 404 karena kepemilikan atau kategori tidak cocok.
     *
     * @param  array<string, mixed>  $candidate
     */
    private function scopedValidPath(Employee $employee, string $requiredSkKey, array $candidate): ?string
    {
        if (blank($candidate['file_path'] ?? null)) {
            return null;
        }

        if (array_key_exists('document', $candidate)) {
            return $this->attachments->availableDocumentPath($employee, $candidate['document'], [$requiredSkKey]);
        }

        return $this->attachments->availablePath(
            $employee,
            self::ATTACHMENT_TYPES[$requiredSkKey],
            $candidate['history'],
        );
    }

    /**
     * @param  Collection<int, object>  $histories  Koleksi riwayat sudah diurutkan terbaru di depan.
     * @return list<array{file_path: string|null, source: string, nomor_sk: string|null, tanggal_sk: string|null, history: object}>
     */
    private function historySources(Collection $histories): array
    {
        return $histories
            ->map(fn (object $history): array => [
                'file_path' => $history->file_sk,
                'source' => 'Riwayat Pegawai',
                'nomor_sk' => $history->no_sk,
                'tanggal_sk' => $history->tanggal_sk?->toDateString(),
                'history' => $history,
            ])
            ->all();
    }
}
