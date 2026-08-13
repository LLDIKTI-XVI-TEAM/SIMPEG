<?php

namespace App\Services\Employees;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
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

        $path = $history->getAttribute($config['path']);
        if (! is_string($path) || $path === '' || ! Storage::disk(Document::STORAGE_DISK)->exists($path)) {
            return null;
        }

        if (! array_key_exists($path, $this->documentReferences)) {
            $this->primeDocumentReferences([$path]);
        }

        $conflictingReferenceExists = collect($this->documentReferences[$path])
            ->contains(fn (array $reference): bool => ! hash_equals((string) $employee->id, $reference['employee_id'])
                || $reference['jenis_dokumen'] !== $config['category']);

        return $conflictingReferenceExists ? null : $path;
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
}
