<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Employee;
use App\Models\SkRequirement;
use App\Services\Employees\EmployeeHistoryAttachmentService;
use App\Support\Documents\SkCompleteness;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

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
    private const REQUIRED_SK = SkCompleteness::POOL;

    /** @var array<string, string> */
    private const ATTACHMENT_TYPES = [
        'sk_pengangkatan' => 'appointment',
        'sk_pangkat' => 'rank',
        'sk_jabatan' => 'position',
        'sk_kgb' => 'salary',
    ];

    /**
     * Matriks SK wajib per jenis_pegawai_id yang dimuat sekali per pemanggilan
     * service, agar penilaian banyak pegawai (daftar) tidak memicu N+1 query.
     *
     * @var array<string, list<string>>|null
     */
    private ?array $requirementMapByType = null;

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
                $employee->appointments
                    ->sort(function ($a, $b) {
                        $tmtA = $a->tmt_pengangkatan?->timestamp ?? 0;
                        $tmtB = $b->tmt_pengangkatan?->timestamp ?? 0;
                        if ($tmtA !== $tmtB) {
                            return $tmtB <=> $tmtA;
                        }

                        $createdA = $a->created_at?->timestamp ?? 0;
                        $createdB = $b->created_at?->timestamp ?? 0;
                        if ($createdA !== $createdB) {
                            return $createdB <=> $createdA;
                        }

                        return strcmp((string) $b->id, (string) $a->id);
                    })
                    ->values()
            ),
            'sk_pangkat' => $this->historySources(
                $employee->rankHistories
                    ->sort(function ($a, $b) {
                        $latestA = $a->is_latest ? 1 : 0;
                        $latestB = $b->is_latest ? 1 : 0;
                        if ($latestA !== $latestB) {
                            return $latestB <=> $latestA;
                        }

                        $tmtA = $a->tmt_pangkat?->timestamp ?? 0;
                        $tmtB = $b->tmt_pangkat?->timestamp ?? 0;
                        if ($tmtA !== $tmtB) {
                            return $tmtB <=> $tmtA;
                        }

                        return ($b->created_at?->timestamp ?? 0) <=> ($a->created_at?->timestamp ?? 0);
                    })
                    ->values()
            ),
            'sk_jabatan' => $this->historySources(
                $employee->positionHistories
                    ->sort(function ($a, $b) {
                        $latestA = $a->is_latest ? 1 : 0;
                        $latestB = $b->is_latest ? 1 : 0;
                        if ($latestA !== $latestB) {
                            return $latestB <=> $latestA;
                        }

                        $tmtA = $a->tmt_jabatan?->timestamp ?? 0;
                        $tmtB = $b->tmt_jabatan?->timestamp ?? 0;
                        if ($tmtA !== $tmtB) {
                            return $tmtB <=> $tmtA;
                        }

                        return ($b->created_at?->timestamp ?? 0) <=> ($a->created_at?->timestamp ?? 0);
                    })
                    ->values()
            ),
            'sk_kgb' => $this->historySources(
                $employee->salaryHistories
                    ->sort(function ($a, $b) {
                        $latestA = $a->is_latest ? 1 : 0;
                        $latestB = $b->is_latest ? 1 : 0;
                        if ($latestA !== $latestB) {
                            return $latestB <=> $latestA;
                        }

                        $tmtA = $a->tmt_kgb?->timestamp ?? 0;
                        $tmtB = $b->tmt_kgb?->timestamp ?? 0;
                        if ($tmtA !== $tmtB) {
                            return $tmtB <=> $tmtA;
                        }

                        return ($b->created_at?->timestamp ?? 0) <=> ($a->created_at?->timestamp ?? 0);
                    })
                    ->values()
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

        $requiredSksMap = $this->requiredSkFor($employee);

        foreach ($requiredSksMap as $key => $label) {
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
                    : ($this->physicalFileExists($canonical['file_path'])
                        ? 'File tidak dapat diakses (konflik metadata)'
                        : 'File tidak ditemukan di storage');
                $perluPerbaikanCount++;
            }

            $metadataSource = $available ?? $candidates->first();
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

        $totalWajib = count($requiredSksMap);
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
     * SK wajib untuk jenis pegawai sang pegawai.
     *
     * Matriks dikonfigurasi super admin pada tabel sk_requirements. Jenis
     * pegawai yang belum memiliki baris sama sekali (belum dikonfigurasi) memakai
     * seluruh SK sebagai fallback (perilaku lama); jenis yang sudah dikonfigurasi
     * memakai is_wajib apa adanya (boleh kosong).
     *
     * @return array<string, string> peta sk_key => label
     */
    private function requiredSkFor(Employee $employee): array
    {
        $typeId = $employee->jenis_pegawai_id;

        if ($typeId !== null) {
            $keys = $this->requirementMapByType()[$typeId] ?? null;
            if ($keys !== null) {
                return array_intersect_key(self::REQUIRED_SK, array_flip($keys));
            }
        }

        return self::REQUIRED_SK;
    }

    /**
     * @return array<string, list<string>>
     */
    private function requirementMapByType(): array
    {
        if ($this->requirementMapByType !== null) {
            return $this->requirementMapByType;
        }

        return $this->requirementMapByType = SkRequirement::query()
            ->where('is_wajib', true)
            ->select('jenis_pegawai_id', 'sk_key')
            ->get()
            ->groupBy('jenis_pegawai_id')
            ->map(fn (Collection $rows): array => $rows->pluck('sk_key')->all())
            ->all();
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
     * Membedakan label penyebab kerusakan: file fisik ada tetapi ditolak scoped
     * validator berarti konflik metadata, bukan file hilang dari storage.
     */
    private function physicalFileExists(string $filePath): bool
    {
        return Storage::disk(Document::STORAGE_DISK)->exists($filePath);
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
