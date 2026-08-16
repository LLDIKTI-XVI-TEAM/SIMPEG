<?php

namespace App\Services\Employees;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use App\Support\Documents\LegacyStatusDocumentResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class EmployeeHistoryAttachmentService
{
    /** @var array<string, list<array{employee_id: string, jenis_dokumen: string}>> */
    private array $documentReferences = [];

    /** @var array<string, array{model: class-string<Model>, path: string, category: string}> */
    private const TYPES = [
        'rank' => ['model' => RankHistory::class, 'path' => 'file_sk', 'category' => 'sk_pangkat'],
        'position' => ['model' => PositionHistory::class, 'path' => 'file_sk', 'category' => 'sk_jabatan'],
        'salary' => ['model' => SalaryHistory::class, 'path' => 'file_sk', 'category' => 'sk_kgb'],
        'appointment' => ['model' => Appointment::class, 'path' => 'file_sk', 'category' => 'sk_pengangkatan'],
        'discipline' => ['model' => DisciplineRecord::class, 'path' => 'file_sk', 'category' => 'sk_hukuman_disiplin'],
        'education' => ['model' => EducationHistory::class, 'path' => 'file_ijazah', 'category' => 'ijazah'],
        'status' => ['model' => EmployeeStatusHistory::class, 'path' => 'file_sk', 'category' => 'sk_status_pegawai'],
    ];

    /** @param iterable<int, mixed> $paths */
    public function primeDocumentReferences(iterable $paths): void
    {
        $paths = collect($paths)
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values();
        if ($paths->isEmpty()) {
            return;
        }

        // Path yang sudah pernah di-prime dalam request yang sama tidak di-query
        // ulang agar priming tingkat halaman dan tingkat baris tidak menggandakan query.
        $paths = $paths
            ->reject(fn (string $path): bool => array_key_exists($path, $this->documentReferences))
            ->values();

        if ($paths->isEmpty()) {
            return;
        }

        foreach ($paths as $path) {
            $this->documentReferences[$path] = [];
        }

        Document::query()
            ->whereIn('file_path', $paths)
            ->get(['employee_id', 'jenis_dokumen', 'file_path'])
            ->each(function (Document $document): void {
                $this->documentReferences[$document->file_path][] = [
                    'employee_id' => $document->employee_id,
                    'jenis_dokumen' => $document->jenis_dokumen,
                ];
            });
    }

    /**
     * Mengembalikan path hanya bila record dimiliki pegawai, file privat tersedia,
     * dan setiap metadata dokumen yang merujuk path tersebut konsisten dengan tipe riwayat.
     *
     * Row legacy tanpa metadata dokumen tetap didukung karena riwayat adalah sumber resmi lama;
     * metadata yang ada tetapi lintas pegawai/kategori ditolak secara fail-closed.
     */
    public function availablePath(Employee $employee, string $type, Model $history): ?string
    {
        $config = self::TYPES[$type] ?? null;
        if ($config === null
            || ! $history instanceof $config['model']
            || ! hash_equals((string) $employee->id, (string) $history->getAttribute('employee_id'))) {
            return null;
        }

        return $this->availableEmployeePath(
            $employee,
            $history->getAttribute($config['path']),
            [$config['category']],
        );
    }

    /**
     * Mengembalikan path dokumen hanya bila metadata pemilik dan kategorinya tidak ambigu.
     *
     * @param  list<string>  $allowedCategories
     */
    public function availableDocumentPath(
        Employee $employee,
        Document $document,
        array $allowedCategories,
    ): ?string {
        if (! hash_equals((string) $employee->id, (string) $document->employee_id)
            || ! in_array($document->jenis_dokumen, $allowedCategories, true)) {
            return null;
        }

        return $this->availableEmployeePath(
            $employee,
            $document->file_path,
            [$document->jenis_dokumen],
        );
    }

    /**
     * Menyelesaikan SK status langsung atau fallback metadata legacy dengan aturan scope yang sama.
     */
    public function availableStatusPath(Employee $employee, EmployeeStatusHistory $history): ?string
    {
        if (! hash_equals((string) $employee->id, (string) $history->employee_id)) {
            return null;
        }

        if (is_string($history->file_sk) && $history->file_sk !== '') {
            return $this->availableEmployeePath(
                $employee,
                $history->file_sk,
                $this->statusDocumentCategories($history->status_nama),
            );
        }

        $documents = $employee->relationLoaded('documents')
            ? $employee->documents
            : $employee->documents()->where('jenis_dokumen', 'sk_status_pegawai')->orderBy('id')->get();

        return $this->availableEmployeePath(
            $employee,
            LegacyStatusDocumentResolver::resolve($documents, $history)?->file_path,
            $this->statusDocumentCategories($history->status_nama),
        );
    }

    /** Snapshot legacy tetap boleh tanpa metadata, tetapi konflik metadata wajib ditolak. */
    public function availableStatusSnapshotPath(Employee $employee): ?string
    {
        $statusName = $employee->relationLoaded('statusPegawai')
            ? $employee->statusPegawai?->nama
            : $employee->statusPegawai()->value('nama');

        return $this->availableEmployeePath(
            $employee,
            $employee->status_berkas_path,
            $this->statusDocumentCategories($statusName ?? $employee->getRawOriginal('status_aktif')),
        );
    }

    public function statusDownloadUrl(
        Employee $employee,
        EmployeeStatusHistory $history,
        string $routeName,
        bool $includeType = true,
    ): ?string {
        if ($this->availableStatusPath($employee, $history) === null) {
            return null;
        }

        return $this->statusRoute($employee, $history, 'status', $routeName, $includeType);
    }

    public function statusSnapshotDownloadUrl(
        Employee $employee,
        string $routeName,
        bool $includeType = true,
    ): ?string {
        if ($this->availableStatusSnapshotPath($employee) === null) {
            return null;
        }

        return $this->statusRoute($employee, $employee, 'status-snapshot', $routeName, $includeType);
    }

    /** @param list<string> $categories */
    private function availableEmployeePath(Employee $employee, mixed $path, array $categories): ?string
    {
        if (! is_string($path) || $path === '' || ! Storage::disk(Document::STORAGE_DISK)->exists($path)) {
            return null;
        }

        if (! array_key_exists($path, $this->documentReferences)) {
            $this->primeDocumentReferences([$path]);
        }

        $conflictingReferenceExists = collect($this->documentReferences[$path])
            ->contains(fn (array $reference): bool => ! hash_equals((string) $employee->id, $reference['employee_id'])
                || ! in_array($reference['jenis_dokumen'], $categories, true));

        return $conflictingReferenceExists ? null : $path;
    }

    /**
     * SK pensiun dihasilkan alur persetujuan EWS sebagai SK status yang sah,
     * tetapi kategorinya hanya boleh diterima saat status pegawai memang Pensiun.
     *
     * @return list<string>
     */
    private function statusDocumentCategories(?string $statusName): array
    {
        $categories = ['sk_status_pegawai'];

        if (mb_strtolower(trim((string) $statusName)) === 'pensiun') {
            $categories[] = 'sk_pensiun';
        }

        return $categories;
    }

    public function downloadUrl(
        Employee $employee,
        string $type,
        Model $history,
        string $routeName,
        bool $includeType = true,
    ): ?string {
        if ($this->availablePath($employee, $type, $history) === null) {
            return null;
        }

        $parameters = [
            'employee' => $employee,
            'history' => $history,
        ];
        if ($includeType) {
            $parameters['type'] = $type;
        }

        return route($routeName, $parameters);
    }

    private function statusRoute(
        Employee $employee,
        Model $history,
        string $type,
        string $routeName,
        bool $includeType,
    ): string {
        $parameters = ['employee' => $employee, 'history' => $history];
        if ($includeType) {
            $parameters['type'] = $type;
        }

        return route($routeName, $parameters);
    }
}
