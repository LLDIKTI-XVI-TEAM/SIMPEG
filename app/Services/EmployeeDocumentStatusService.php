<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Employee;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Menilai kelengkapan empat SK wajib pegawai berdasarkan data dan file fisik.
 */
class EmployeeDocumentStatusService
{
    public function __construct(
        private readonly EmployeeHistoryAttachmentService $attachments,
    ) {}

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
        $jenisPegawai = strtolower($employee->jenisPegawai?->nama ?? '');
        $isPns = $jenisPegawai === 'pns';

        $requiredSksMatrix = $isPns ? self::REQUIRED_SK : [];

        // Riwayat dikirim dalam urutan kanonis: terbaru di depan.
        // Urutan ini konsisten dengan repairableHistory() di StoreDocumentAction
        // sehingga evaluasi status selalu mengacu pada riwayat yang paling mutakhir.
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

        foreach ($employee->documents as $document) {
            if (! array_key_exists($document->jenis_dokumen, $requiredSksMatrix)) {
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

        // Validasi konflik metadata (path diklaim Document pegawai/kategori lain)
        // memakai cache referensi yang di-prime sekali — tanpa query per kandidat.
        // Pegawai tanpa berkas sama sekali tetap bebas query.
        $candidatePaths = collect($sources)
            ->flatten(1)
            ->filter(fn (array $candidate): bool => filled($candidate['file_path'] ?? null))
            ->pluck('file_path')
            ->unique()
            ->values();
        if ($candidatePaths->isNotEmpty()) {
            $this->attachments->primeDocumentReferences($candidatePaths);
        }

        $disk = Storage::disk(Document::STORAGE_DISK);
        $requiredSks = [];
        $tersediaCount = 0;
        $belumAdaCount = 0;
        $perluPerbaikanCount = 0;

        foreach ($requiredSksMatrix as $key => $label) {
            $candidates = collect($sources[$key]);

            // Riwayat kanonis adalah kandidat pertama dari historySources (sudah diurutkan
            // terbaru di depan). Jika ia rusak — file_sk null atau file fisik hilang —
            // langsung perlu_perbaikan tanpa memeriksa kandidat lain. Ini mencegah
            // riwayat lama yang masih valid menyembunyikan kerusakan riwayat terbaru.
            $canonical = $candidates->first();
            $canonicalBroken = $canonical !== null
                && $canonical['source'] === 'Riwayat Pegawai'
                && ! $this->candidatePathUsable($employee, $key, $canonical, $disk);

            $available = $canonicalBroken
                ? null
                : $candidates->first(fn (array $candidate): bool => $this->candidatePathUsable($employee, $key, $candidate, $disk));

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
                    : ($disk->exists($canonical['file_path']) ? 'File tidak dapat diakses (konflik metadata)' : 'File tidak ditemukan di storage');
                $perluPerbaikanCount++;
            }

            $filePath = $available['file_path'] ?? null;
            $metadataSource = $candidates->first();
            $requiredSks[] = [
                'jenis' => $key,
                'label' => $label,
                'status' => $state,
                'status_label' => $statusLabel,
                'file_path' => $filePath,
                'file_url' => $this->resolveFileUrl($employee, $key, $available),
                'nomor_sk' => $metadataSource['nomor_sk'] ?? null,
                'tanggal_sk' => $metadataSource['tanggal_sk'] ?? null,
                'sources_count' => $candidates->count(),
            ];
        }

        $totalWajib = count($requiredSksMatrix);
        $statusKelengkapan = match (true) {
            $totalWajib === 0 => 'tidak_wajib',
            $perluPerbaikanCount > 0 => 'perlu_perbaikan',
            $tersediaCount === $totalWajib => 'lengkap',
            $belumAdaCount === $totalWajib => 'belum_ada',
            default => 'belum_lengkap',
        };

        return [
            'status_kelengkapan' => $statusKelengkapan,
            'is_lengkap' => $statusKelengkapan === 'lengkap' || $statusKelengkapan === 'tidak_wajib',
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
     * Kandidat hanya dianggap tersedia bila file fisik ada DAN metadata referensinya
     * tidak diklaim Document milik pegawai/kategori lain (fail-closed, selaras dengan
     * EmployeeHistoryAttachmentService::availablePath pada endpoint unduh).
     *
     * @param  array<string, mixed>  $candidate
     */
    private function candidatePathUsable(Employee $employee, string $requiredSkKey, array $candidate, Filesystem $disk): bool
    {
        if (blank($candidate['file_path'] ?? null) || ! $disk->exists($candidate['file_path'])) {
            return false;
        }

        if (array_key_exists('document', $candidate)) {
            return $this->attachments->availableDocumentPath($employee, $candidate['document'], [$requiredSkKey]) !== null;
        }

        return $this->attachments->availablePath(
            $employee,
            self::ATTACHMENT_TYPES[$requiredSkKey],
            $candidate['history'],
        ) !== null;
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
