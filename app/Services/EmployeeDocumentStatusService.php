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
 * Menghitung kelengkapan SK dari matriks aktif dan sumber riwayat kanonis.
 *
 * Pemeriksaan file memakai validator yang sama dengan route unduh sehingga file
 * lintas pegawai/kategori dan file fisik hilang tidak pernah dihitung tersedia.
 */
class EmployeeDocumentStatusService
{
    /** @var array<string, string> */
    private const ATTACHMENT_TYPES = [
        'sk_pengangkatan' => 'appointment',
        'sk_pangkat' => 'rank',
        'sk_jabatan' => 'position',
        'sk_kgb' => 'salary',
    ];

    /** @var array<string, list<string>>|null */
    private ?array $requirementMapByType = null;

    public function __construct(
        private readonly EmployeeHistoryAttachmentService $attachments,
    ) {}

    /**
     * Memuat metadata referensi file sekali untuk satu halaman pegawai agar
     * kalkulasi setiap baris tidak menimbulkan query metadata N+1.
     *
     * @param  iterable<int, Employee>  $employees
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
        $this->requirementMapByType();
    }

    /**
     * @return array{
     *     status_kelengkapan: string,
     *     is_dinilai: bool,
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
        $requiredSksMap = $this->requiredSkFor($employee);
        if ($requiredSksMap === []) {
            return [
                'status_kelengkapan' => 'tidak_dinilai',
                'is_dinilai' => false,
                'is_lengkap' => false,
                'total_wajib' => 0,
                'tersedia_count' => 0,
                'belum_ada_count' => 0,
                'perlu_perbaikan_count' => 0,
                'required_sks' => [],
            ];
        }

        $sources = [
            'sk_pengangkatan' => $this->historySources(
                $employee->appointments
                    // SK Pengangkatan berasal dari pengangkatan pertama; berbeda
                    // dari pangkat, jabatan, dan KGB yang memakai riwayat terbaru.
                    ->sort(fn ($a, $b): int => $this->compareFirstAppointment($a, $b))
                    ->values(),
            ),
            'sk_pangkat' => $this->historySources(
                $employee->rankHistories
                    ->sort(fn ($a, $b): int => $this->compareCanonical($a, $b, 'tmt_pangkat', true))
                    ->values(),
            ),
            'sk_jabatan' => $this->historySources(
                $employee->positionHistories
                    ->sort(fn ($a, $b): int => $this->compareCanonical($a, $b, 'tmt_jabatan', true))
                    ->values(),
            ),
            'sk_kgb' => $this->historySources(
                $employee->salaryHistories
                    ->sort(fn ($a, $b): int => $this->compareCanonical($a, $b, 'tmt_kgb', true))
                    ->values(),
            ),
        ];

        foreach ($employee->documents->sortByDesc('created_at')->values() as $document) {
            if (! array_key_exists($document->jenis_dokumen, SkCompleteness::POOL)) {
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

        $this->attachments->primeDocumentReferences(
            collect($sources)->flatten(1)->pluck('file_path'),
        );

        $requiredSks = [];
        $availableCount = 0;
        $missingCount = 0;
        $repairCount = 0;

        foreach ($requiredSksMap as $key => $label) {
            $candidates = collect($sources[$key]);
            $canonical = $candidates->first();
            $canonicalBroken = $canonical !== null
                && $canonical['source'] === 'Riwayat Pegawai'
                && $this->scopedValidPath($employee, $key, $canonical) === null;

            $available = $canonicalBroken
                ? null
                : $candidates->first(
                    fn (array $candidate): bool => $this->scopedValidPath($employee, $key, $candidate) !== null,
                );

            if ($available !== null) {
                $state = 'tersedia';
                $statusLabel = 'File tersedia';
                $availableCount++;
            } elseif ($candidates->isEmpty()) {
                $state = 'belum_ada';
                $statusLabel = 'Belum ada data SK';
                $missingCount++;
            } else {
                $state = 'perlu_perbaikan';
                $statusLabel = blank($canonical['file_path'] ?? null)
                    ? 'Berkas belum diunggah'
                    : ($this->physicalFileExists((string) $canonical['file_path'])
                        ? 'File tidak dapat diakses (konflik metadata)'
                        : 'File tidak ditemukan di storage');
                $repairCount++;
            }

            $metadataSource = $available ?? $canonical;
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

        $totalRequired = count($requiredSksMap);
        $status = match (true) {
            $repairCount > 0 => 'perlu_perbaikan',
            $availableCount === $totalRequired => 'lengkap',
            $missingCount === $totalRequired => 'belum_ada',
            default => 'belum_lengkap',
        };

        return [
            'status_kelengkapan' => $status,
            'is_dinilai' => true,
            'is_lengkap' => $status === 'lengkap',
            'total_wajib' => $totalRequired,
            'tersedia_count' => $availableCount,
            'belum_ada_count' => $missingCount,
            'perlu_perbaikan_count' => $repairCount,
            'required_sks' => $requiredSks,
        ];
    }

    /**
     * Urutan kanonis: is_latest, TMT, created_at, lalu UUID. Nilai terbesar
     * ditempatkan di depan agar hasil stabil ketika tanggal sama.
     */
    private function compareCanonical(object $a, object $b, string $tmtField, bool $usesLatest): int
    {
        if ($usesLatest) {
            $latestA = $a->is_latest ? 1 : 0;
            $latestB = $b->is_latest ? 1 : 0;
            if ($latestA !== $latestB) {
                return $latestB <=> $latestA;
            }
        }

        $tmtA = $a->{$tmtField}?->timestamp ?? 0;
        $tmtB = $b->{$tmtField}?->timestamp ?? 0;
        if ($tmtA !== $tmtB) {
            return $tmtB <=> $tmtA;
        }

        $createdA = $a->created_at?->timestamp ?? 0;
        $createdB = $b->created_at?->timestamp ?? 0;
        if ($createdA !== $createdB) {
            return $createdB <=> $createdA;
        }

        return strcmp((string) $b->id, (string) $a->id);
    }

    /**
     * Mengurutkan pengangkatan pertama secara stabil. TMT yang valid selalu
     * didahulukan; created_at dan UUID menjadi penentu ketika TMT sama.
     */
    private function compareFirstAppointment(object $a, object $b): int
    {
        $tmtA = $a->tmt_pengangkatan?->timestamp;
        $tmtB = $b->tmt_pengangkatan?->timestamp;
        if ($tmtA !== $tmtB) {
            if ($tmtA === null) {
                return 1;
            }
            if ($tmtB === null) {
                return -1;
            }

            return $tmtA <=> $tmtB;
        }

        $createdA = $a->created_at?->timestamp;
        $createdB = $b->created_at?->timestamp;
        if ($createdA !== $createdB) {
            if ($createdA === null) {
                return 1;
            }
            if ($createdB === null) {
                return -1;
            }

            return $createdA <=> $createdB;
        }

        return strcmp((string) $a->id, (string) $b->id);
    }

    /** @return array<string, string> */
    private function requiredSkFor(Employee $employee): array
    {
        $typeId = $employee->jenis_pegawai_id;
        if ($typeId === null) {
            return [];
        }

        $keys = $this->requirementMapByType()[$typeId] ?? [];

        return array_intersect_key(SkCompleteness::POOL, array_flip($keys));
    }

    /** @return array<string, list<string>> */
    private function requirementMapByType(): array
    {
        if ($this->requirementMapByType !== null) {
            return $this->requirementMapByType;
        }

        return $this->requirementMapByType = SkRequirement::query()
            ->where('is_wajib', true)
            ->orderBy('sk_key')
            ->get(['jenis_pegawai_id', 'sk_key'])
            ->groupBy('jenis_pegawai_id')
            ->map(fn (Collection $rows): array => $rows->pluck('sk_key')->values()->all())
            ->all();
    }

    /** @param array<string, mixed>|null $candidate */
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

    /** @param array<string, mixed> $candidate */
    private function scopedValidPath(Employee $employee, string $requiredSkKey, array $candidate): ?string
    {
        if (blank($candidate['file_path'] ?? null)) {
            return null;
        }

        if (array_key_exists('document', $candidate)) {
            return $this->attachments->availableDocumentPath(
                $employee,
                $candidate['document'],
                [$requiredSkKey],
            );
        }

        return $this->attachments->availablePath(
            $employee,
            self::ATTACHMENT_TYPES[$requiredSkKey],
            $candidate['history'],
        );
    }

    private function physicalFileExists(string $filePath): bool
    {
        return Storage::disk(Document::STORAGE_DISK)->exists($filePath);
    }

    /**
     * @param  Collection<int, object>  $histories
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
