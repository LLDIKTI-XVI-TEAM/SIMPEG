<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\StorageRecoveryTask;
use App\Services\AuditService;
use App\Services\Cuti\LeaveProofDocumentStorageService;
use App\Services\StorageRecoveryService;
use App\Support\Storage\BoundedStorageHasher;
use finfo;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrateEmployeeDocumentsToPrivateStorage extends Command
{
    private const ORPHAN_QUARANTINE_PREFIX = 'quarantine/orphaned-public';

    /** @var list<string> */
    private const LEAVE_ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    private const LEAVE_ATTACHMENT_SCAN_BATCH_SIZE = 100;

    private const LEAVE_PROOF_SCAN_BATCH_SIZE = 100;

    private const DOCUMENT_REFERENCE_SCAN_BATCH_SIZE = 100;

    private const LEAVE_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

    private const LEAVE_PROOF_MAX_BYTES = 10 * 1024 * 1024;

    private const STREAM_CHUNK_BYTES = 8192;

    /** @var array<string, list<string>> */
    private const LEAVE_ATTACHMENT_MIME_BY_EXTENSION = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    /** @var list<string> */
    private const LEGACY_DOCUMENT_PREFIXES = [
        'appointments/sk/',
        'berkas/',
        'pegawai/',
        'positions/sk/',
        'ranks/sk/',
        'salaries/sk/',
        'sk/',
    ];

    /** @var list<array{0: string, 1: string}> */
    private const DOCUMENT_REFERENCE_SOURCES = [
        ['documents', 'file_path'],
        ['rank_histories', 'file_sk'],
        ['position_histories', 'file_sk'],
        ['salary_histories', 'file_sk'],
        ['appointments', 'file_sk'],
        ['discipline_records', 'file_sk'],
        ['education_histories', 'file_ijazah'],
        ['employee_status_histories', 'file_sk'],
        ['employees', 'status_berkas_path'],
    ];

    protected $signature = 'documents:migrate-to-private-storage
        {--execute : Salin target privat terverifikasi, commit metadata, lalu hapus source legacy privat/publik}';

    protected $description = 'Migrasikan dokumen pegawai/lampiran publik serta bukti cuti legacy privat ke path privat kanonis';

    public function __construct(private readonly StorageRecoveryService $recovery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $this->info($execute ? 'Mode execute: migrasi dimulai.' : 'Mode dry-run: tidak ada file yang diubah.');

        $public = Storage::disk('public');
        $private = Storage::disk(Document::STORAGE_DISK);
        $leavePrivate = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);
        $counts = [
            'dipindahkan' => 0,
            'dikarantina' => 0,
            'siap' => 0,
            'sudah_privat' => 0,
            'hilang' => 0,
            'konflik' => 0,
            'tidak_valid' => 0,
            'yatim' => 0,
        ];
        foreach ($this->referencedPaths() as $path) {
            $publicExists = $public->exists($path);
            $privateExists = $private->exists($path);

            if (! $publicExists) {
                $counts[$privateExists ? 'sudah_privat' : 'hilang']++;

                continue;
            }

            if ($privateExists && ! $this->sameContents($public, $private, $path)) {
                $counts['konflik']++;
                $this->warn("konflik: {$path}; file publik dan privat dibiarkan utuh.");

                continue;
            }

            if (! $execute) {
                $counts['siap']++;

                continue;
            }

            if (! $privateExists) {
                if (! $this->copyAndVerify($public, $path, $private, $path)) {
                    if ($private->exists($path)
                        && (! $private->delete($path) || $private->exists($path))) {
                        $this->warn("konflik: salinan privat parsial {$path} gagal dibersihkan.");
                    }
                    $counts['konflik']++;
                    $this->warn("konflik: verifikasi salinan privat gagal untuk {$path}; file publik dipertahankan.");

                    continue;
                }
            }

            if (! $public->delete($path) || $public->exists($path)) {
                $counts['konflik']++;
                $this->warn("konflik: salinan publik {$path} gagal dihapus setelah verifikasi.");

                continue;
            }

            $counts['dipindahkan']++;
        }

        $this->migrateReferencedLeaveAttachments($public, $leavePrivate, $execute, $counts);
        $this->migrateReferencedLeaveProofs($leavePrivate, $execute, $counts);

        foreach ($this->orphanedPublicDocumentPaths($public) as $path) {
            if (! $execute) {
                $counts['yatim']++;
                $this->warn("yatim: {$path}; file publik tidak memiliki referensi database.");

                continue;
            }

            if ($this->quarantineOrphan($public, $private, $path)) {
                $counts['dikarantina']++;
            } else {
                $counts['konflik']++;
            }
        }

        $this->line(sprintf(
            'Ringkasan: dipindahkan=%d, dikarantina=%d, siap=%d, sudah_privat=%d, hilang=%d, konflik=%d, tidak_valid=%d, yatim=%d.',
            $counts['dipindahkan'],
            $counts['dikarantina'],
            $counts['siap'],
            $counts['sudah_privat'],
            $counts['hilang'],
            $counts['konflik'],
            $counts['tidak_valid'],
            $counts['yatim'],
        ));

        return $counts['konflik'] > 0
            || $counts['hilang'] > 0
            || $counts['tidak_valid'] > 0
            || $counts['yatim'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * Mengambil hanya path dokumen pegawai yang masih direferensikan database agar migrasi tidak menyapu file lain.
     *
     * @return iterable<string>
     */
    private function referencedPaths(): iterable
    {
        $lastPath = null;

        do {
            $query = DB::query()
                ->fromSub($this->referencedPathUnion(), 'referenced_document_paths')
                ->select('path')
                ->distinct()
                ->orderBy('path')
                ->limit(self::DOCUMENT_REFERENCE_SCAN_BATCH_SIZE);

            if ($lastPath !== null) {
                $query->where('path', '>', $lastPath);
            }

            /** @var Collection<int, string> $paths */
            $paths = $query->pluck('path')->map(fn (mixed $path): string => (string) $path);
            foreach ($paths as $path) {
                yield $path;
            }

            $lastPath = $paths->last();
        } while ($paths->count() === self::DOCUMENT_REFERENCE_SCAN_BATCH_SIZE && $lastPath !== null);
    }

    private function referencedPathUnion(): Builder
    {
        $sources = collect(self::DOCUMENT_REFERENCE_SOURCES);
        [$firstTable, $firstColumn] = $sources->shift();
        $union = DB::table($firstTable)
            ->select($firstColumn.' as path')
            ->whereNotNull($firstColumn)
            ->where($firstColumn, '<>', '');

        foreach ($sources as [$table, $column]) {
            $union->unionAll(
                DB::table($table)
                    ->select($column.' as path')
                    ->whereNotNull($column)
                    ->where($column, '<>', ''),
            );
        }

        return $union;
    }

    private function sameContents(Filesystem $public, Filesystem $private, string $path): bool
    {
        return $this->sameContentsAt($public, $path, $private, $path);
    }

    /**
     * Memigrasikan setiap lampiran cuti legacy yang masih direferensikan ke namespace privat kanonis.
     * Satu path sumber diproses sebagai satu unit agar referensi bersama tidak kehilangan file di tengah migrasi.
     *
     * @param  array<string, int>  $counts
     */
    private function migrateReferencedLeaveAttachments(
        Filesystem $public,
        Filesystem $private,
        bool $execute,
        array &$counts,
    ): void {
        $lastPath = null;

        do {
            $pathQuery = DB::table('leave_requests')
                ->select('lampiran_path')
                ->whereNotNull('lampiran_path')
                ->where('lampiran_path', '<>', '')
                ->distinct()
                ->orderBy('lampiran_path')
                ->limit(self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE);

            if ($lastPath !== null) {
                // Keyset dipakai karena OFFSET dapat melewati source saat path lama diperbarui di tengah command.
                $pathQuery->where('lampiran_path', '>', $lastPath);
            }

            /** @var Collection<int, string> $paths */
            $paths = $pathQuery->pluck('lampiran_path')
                ->map(fn (mixed $path): string => (string) $path);

            foreach ($paths as $path) {
                $this->migrateLeaveAttachmentSource($public, $private, $path, $execute, $counts);
            }

            $lastPath = $paths->last();
        } while ($paths->count() === self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE && $lastPath !== null);
    }

    /**
     * Menangani salinan publik pada path kanonis dengan intent target sebelum write dan task source sesudah commit.
     *
     * @param  array<string, int>  $counts
     */
    private function migrateCanonicalLeaveAttachment(
        Filesystem $public,
        Filesystem $private,
        string $path,
        string $ownerId,
        int $referenceCount,
        bool $execute,
        array &$counts,
    ): void {
        $publicExists = $public->exists($path);
        $privateExists = $private->exists($path);

        if (! $publicExists) {
            if (! $privateExists) {
                $counts['hilang'] += $referenceCount;
                $this->warn("hilang: lampiran cuti privat {$path} tidak ditemukan.");

                return;
            }

            $source = $this->validatedLeaveAttachmentSource($private, $path);
            try {
                $needsAdoption = $this->recovery->canonicalLeaveAttachmentNeedsAdoption(
                    $path,
                    $ownerId,
                    $source['sha256'],
                );
                if (! $needsAdoption) {
                    $counts['sudah_privat'] += $referenceCount;

                    return;
                }
                if (! $execute) {
                    $counts['siap'] += $referenceCount;

                    return;
                }

                $this->recovery->adoptReferencedCanonicalLeaveAttachment(
                    $path,
                    $ownerId,
                    $source['sha256'],
                );
                $counts['dipindahkan'] += $referenceCount;
            } finally {
                fclose($source['stream']);
            }

            return;
        }

        $source = $this->validatedLeaveAttachmentSource($public, $path);
        try {
            if ($privateExists && ! hash_equals($source['sha256'], $this->hashPath($private, $path))) {
                $counts['konflik'] += $referenceCount;
                $this->warn("konflik: lampiran cuti publik dan privat {$path} memiliki isi berbeda.");

                return;
            }
            if (! $execute) {
                $counts['siap'] += $referenceCount;

                return;
            }

            if (! $privateExists) {
                $this->prepareCanonicalMigrationTarget($path, $ownerId, $source['sha256']);
            }

            /** @var array{task_id: string, reference_count: int} $result */
            $result = DB::transaction(function () use ($private, $path, $ownerId, $privateExists, $source): array {
                $targetIntent = null;
                if (! $privateExists) {
                    $targetIntent = StorageRecoveryTask::query()
                        ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                        ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                        ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
                        ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
                        ->where('path', $path)
                        ->where('owner_id', $ownerId)
                        ->where('source_disk', 'public')
                        ->where('source_path', $path)
                        ->where('sha256', $source['sha256'])
                        ->lockForUpdate()
                        ->sole();
                }

                $lockedReferenceCount = $this->lockAndValidateCanonicalReferences($path, $ownerId);
                if ($lockedReferenceCount === 0) {
                    throw new \RuntimeException('Referensi kanonis berubah sebelum migrasi dijalankan.');
                }

                if ($privateExists) {
                    if (! $private->exists($path)
                        || ! hash_equals($source['sha256'], $this->hashPath($private, $path))) {
                        throw new \RuntimeException('Counterpart privat kanonis berubah sebelum task cleanup dibuat.');
                    }
                    $this->recovery->adoptReferencedCanonicalLeaveAttachment(
                        $path,
                        $ownerId,
                        $source['sha256'],
                    );
                } else {
                    $targetExists = $private->exists($path);
                    if ($targetExists && ! hash_equals($source['sha256'], $this->hashPath($private, $path))) {
                        throw new \RuntimeException('Target privat kanonis muncul dengan isi berbeda sebelum write.');
                    }
                    if (! $targetExists && ! $this->writeBufferedStream($private, $path, $source['stream'])) {
                        throw new \RuntimeException("Penulisan salinan privat gagal untuk {$path}.");
                    }
                    if (! hash_equals($source['sha256'], $this->hashPath($private, $path))) {
                        throw new \RuntimeException("Verifikasi salinan privat gagal untuk {$path}.");
                    }
                    $this->recovery->markMigrationTargetAdopted($targetIntent);
                }

                $sourceTask = $this->recovery->scheduleDelete(
                    StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY,
                    'public',
                    $path,
                    $ownerId,
                    LeaveRequest::ATTACHMENT_STORAGE_DISK,
                    $path,
                    $source['sha256'],
                );

                return ['task_id' => $sourceTask->id, 'reference_count' => $lockedReferenceCount];
            });

            if (! $this->recovery->attempt($result['task_id'])) {
                $counts['konflik'] += $result['reference_count'];
                $this->warn("konflik: cleanup salinan publik kanonis {$path} tertunda pada manifest recovery.");

                return;
            }

            $counts['dipindahkan'] += $result['reference_count'];
        } catch (\Throwable $exception) {
            $counts['konflik'] += max(1, $referenceCount);
            $this->warn("konflik: migrasi lampiran cuti kanonis {$path} dibatalkan; {$exception->getMessage()}");
        } finally {
            fclose($source['stream']);
        }
    }

    /** Intent kanonis dibuat pada transaksi pendahuluan yang selesai sebelum target disentuh. */
    private function prepareCanonicalMigrationTarget(string $path, string $ownerId, string $sha256): void
    {
        DB::transaction(function () use ($path, $ownerId, $sha256): void {
            $prepared = StorageRecoveryTask::query()
                ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
                ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
                ->where('path', $path)
                ->where('owner_id', $ownerId)
                ->where('source_disk', 'public')
                ->where('source_path', $path)
                ->where('sha256', $sha256)
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($prepared->count() > 1) {
                throw new \RuntimeException('Lebih dari satu intent target kanonis berada pada status prepared.');
            }
            if ($prepared->isEmpty()) {
                $this->recovery->prepareMigrationTarget(
                    LeaveRequest::ATTACHMENT_STORAGE_DISK,
                    $path,
                    $ownerId,
                    'public',
                    $path,
                    $sha256,
                );
            }
        });
    }

    /** Menyiapkan tepat satu target per employee menggunakan keyset agar memori tetap bounded. */
    private function prepareLegacyMigrationTargets(string $sourcePath, string $sha256, string $extension): void
    {
        DB::transaction(function () use ($sourcePath, $sha256, $extension): void {
            $lastEmployeeId = null;
            $lastLeaveId = null;
            $plannedEmployeeId = null;

            do {
                $query = LeaveRequest::query()
                    ->select(['id', 'employee_id'])
                    ->where('lampiran_path', $sourcePath)
                    ->orderBy('employee_id')
                    ->orderBy('id')
                    ->limit(self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE);
                if ($lastEmployeeId !== null && $lastLeaveId !== null) {
                    $query->where(function ($keyset) use ($lastEmployeeId, $lastLeaveId): void {
                        $keyset->where('employee_id', '>', $lastEmployeeId)
                            ->orWhere(function ($sameEmployee) use ($lastEmployeeId, $lastLeaveId): void {
                                $sameEmployee->where('employee_id', $lastEmployeeId)
                                    ->where('id', '>', $lastLeaveId);
                            });
                    });
                }

                $rows = $query->get();
                foreach ($rows as $leave) {
                    $employeeId = (string) $leave->employee_id;
                    if (! Str::isUuid($employeeId)) {
                        throw new \RuntimeException('Employee pada rencana migrasi tidak memakai UUID valid.');
                    }
                    if ($employeeId === $plannedEmployeeId) {
                        continue;
                    }

                    $plannedEmployeeId = $employeeId;
                    $prepared = StorageRecoveryTask::query()
                        ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                        ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                        ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
                        ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
                        ->where('owner_id', $employeeId)
                        ->where('source_disk', 'public')
                        ->where('source_path', $sourcePath)
                        ->where('sha256', $sha256)
                        ->lockForUpdate()
                        ->limit(2)
                        ->get();
                    if ($prepared->count() > 1) {
                        throw new \RuntimeException('Satu employee memiliki lebih dari satu intent target prepared.');
                    }
                    if ($prepared->isEmpty()) {
                        $targetPath = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employeeId.'/'.Str::uuid().'.'.$extension;
                        $this->recovery->prepareMigrationTarget(
                            LeaveRequest::ATTACHMENT_STORAGE_DISK,
                            $targetPath,
                            $employeeId,
                            'public',
                            $sourcePath,
                            $sha256,
                        );
                    } elseif (! $this->isCanonicalLeaveAttachmentPath((string) $prepared->sole()->path, $employeeId)) {
                        throw new \RuntimeException('Intent target prepared memiliki path kanonis yang tidak valid.');
                    }
                }

                $last = $rows->last();
                $lastEmployeeId = $last === null ? null : (string) $last->employee_id;
                $lastLeaveId = $last === null ? null : (string) $last->id;
            } while ($rows->count() === self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE
                && $lastEmployeeId !== null
                && $lastLeaveId !== null);
        });
    }

    /** Semua row intent dikunci sebelum source row agar urutan lock sama dengan processor recovery. */
    private function lockPreparedMigrationTargets(string $sourcePath, string $sha256): int
    {
        $lastId = null;
        $count = 0;

        do {
            $query = $this->preparedMigrationTargetQuery($sourcePath, $sha256)
                ->orderBy('id')
                ->limit(self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE)
                ->lockForUpdate();
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $tasks = $query->get();
            foreach ($tasks as $task) {
                $ownerId = (string) $task->owner_id;
                if (! $this->isCanonicalLeaveAttachmentPath((string) $task->path, $ownerId)) {
                    throw new \RuntimeException('Intent target prepared gagal validasi path dan owner.');
                }
                $count++;
            }
            $lastId = $tasks->last()?->id;
        } while ($tasks->count() === self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE && $lastId !== null);

        return $count;
    }

    /** @return array{0: int, 1: int} */
    private function lockLegacySourceReferences(string $sourcePath): array
    {
        $lastEmployeeId = null;
        $lastLeaveId = null;
        $count = 0;
        $employeeCount = 0;
        $countedEmployeeId = null;

        do {
            $query = LeaveRequest::query()
                ->select(['id', 'employee_id'])
                ->where('lampiran_path', $sourcePath)
                ->orderBy('employee_id')
                ->orderBy('id')
                ->limit(self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE)
                ->lockForUpdate();
            if ($lastEmployeeId !== null && $lastLeaveId !== null) {
                $query->where(function ($keyset) use ($lastEmployeeId, $lastLeaveId): void {
                    $keyset->where('employee_id', '>', $lastEmployeeId)
                        ->orWhere(function ($sameEmployee) use ($lastEmployeeId, $lastLeaveId): void {
                            $sameEmployee->where('employee_id', $lastEmployeeId)
                                ->where('id', '>', $lastLeaveId);
                        });
                });
            }

            $rows = $query->get();
            foreach ($rows as $leave) {
                $employeeId = (string) $leave->employee_id;
                if (! Str::isUuid($employeeId)) {
                    throw new \RuntimeException('Employee pada source terkunci tidak memakai UUID valid.');
                }
                $count++;
                if ($countedEmployeeId !== $employeeId) {
                    $countedEmployeeId = $employeeId;
                    $employeeCount++;
                }
            }

            $last = $rows->last();
            $lastEmployeeId = $last === null ? null : (string) $last->employee_id;
            $lastLeaveId = $last === null ? null : (string) $last->id;
        } while ($rows->count() === self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE
            && $lastEmployeeId !== null
            && $lastLeaveId !== null);

        return [$count, $employeeCount];
    }

    /** Memastikan tidak ada owner hilang, owner ekstra, atau target ganda dalam rencana yang sudah dikunci. */
    private function migrationPlanSetDoesNotMatch(string $sourcePath, string $sha256): bool
    {
        $duplicateOwner = $this->preparedMigrationTargetQuery($sourcePath, $sha256)
            ->select('owner_id')
            ->groupBy('owner_id')
            ->havingRaw('COUNT(*) <> 1')
            ->exists();
        $missingOwner = LeaveRequest::query()
            ->where('lampiran_path', $sourcePath)
            ->whereNotExists(function ($query) use ($sourcePath, $sha256): void {
                $query->selectRaw('1')
                    ->from('storage_recovery_tasks as migration_targets')
                    ->whereColumn('migration_targets.owner_id', 'leave_requests.employee_id')
                    ->where('migration_targets.operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                    ->where('migration_targets.status', StorageRecoveryTask::STATUS_PREPARED)
                    ->where('migration_targets.category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
                    ->where('migration_targets.disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
                    ->where('migration_targets.source_disk', 'public')
                    ->where('migration_targets.source_path', $sourcePath)
                    ->where('migration_targets.sha256', $sha256);
            })
            ->exists();
        $extraOwner = $this->preparedMigrationTargetQuery($sourcePath, $sha256)
            ->whereNotExists(function ($query) use ($sourcePath): void {
                $query->selectRaw('1')
                    ->from('leave_requests as source_requests')
                    ->whereColumn('source_requests.employee_id', 'storage_recovery_tasks.owner_id')
                    ->where('source_requests.lampiran_path', $sourcePath);
            })
            ->exists();

        return $duplicateOwner || $missingOwner || $extraOwner;
    }

    /** Menulis target dan mengadopsi intent per batch tanpa memuat seluruh populasi ke memori. */
    private function writeAndAdoptPreparedTargets(
        Filesystem $private,
        string $sourcePath,
        string $sha256,
        mixed $sourceStream,
    ): int {
        $lastId = null;
        $updatedReferences = 0;

        do {
            $query = $this->preparedMigrationTargetQuery($sourcePath, $sha256)
                ->orderBy('id')
                ->limit(self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE)
                ->lockForUpdate();
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $tasks = $query->get();
            foreach ($tasks as $task) {
                $targetExists = $private->exists($task->path);
                if ($targetExists && ! hash_equals($sha256, $this->hashPath($private, $task->path))) {
                    throw new \RuntimeException("Target privat {$task->path} sudah berisi file berbeda.");
                }
                if (! $targetExists && ! $this->writeBufferedStream($private, $task->path, $sourceStream)) {
                    throw new \RuntimeException("Penulisan salinan privat gagal untuk {$task->path}.");
                }
                if (! hash_equals($sha256, $this->hashPath($private, $task->path))) {
                    throw new \RuntimeException("Verifikasi salinan privat gagal untuk {$task->path}.");
                }

                $updatedReferences += LeaveRequest::query()
                    ->where('employee_id', $task->owner_id)
                    ->where('lampiran_path', $sourcePath)
                    ->update(['lampiran_path' => $task->path]);
                $lastId = (string) $task->id;
                $this->recovery->markMigrationTargetAdopted($task);
            }
        } while ($tasks->count() === self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE && $lastId !== null);

        return $updatedReferences;
    }

    private function lockAndValidateCanonicalReferences(string $path, string $ownerId): int
    {
        $lastId = null;
        $count = 0;

        do {
            $query = LeaveRequest::query()
                ->select(['id', 'employee_id'])
                ->where('lampiran_path', $path)
                ->orderBy('id')
                ->limit(self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE)
                ->lockForUpdate();
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $rows = $query->get();
            foreach ($rows as $leave) {
                if ((string) $leave->employee_id !== $ownerId
                    || ! $this->isCanonicalLeaveAttachmentPath($path, $ownerId)) {
                    throw new \RuntimeException('Referensi kanonis tidak cocok dengan owner path.');
                }
                $count++;
            }
            $lastId = $rows->last()?->id;
        } while ($rows->count() === self::LEAVE_ATTACHMENT_SCAN_BATCH_SIZE && $lastId !== null);

        return $count;
    }

    /** @return EloquentBuilder<StorageRecoveryTask> */
    private function preparedMigrationTargetQuery(string $sourcePath, string $sha256): EloquentBuilder
    {
        return StorageRecoveryTask::query()
            ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
            ->where('status', StorageRecoveryTask::STATUS_PREPARED)
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT)
            ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
            ->where('source_disk', 'public')
            ->where('source_path', $sourcePath)
            ->where('sha256', $sha256);
    }

    /**
     * Protokol dua-fase: intent target di-commit sebelum write; source baru boleh dihapus setelah main commit.
     *
     * @param  array<string, int>  $counts
     */
    private function migrateLeaveAttachmentSource(
        Filesystem $public,
        Filesystem $private,
        string $sourcePath,
        bool $execute,
        array &$counts,
    ): void {
        $referenceCount = 0;
        $allCanonical = true;
        $canonicalOwnerId = null;
        /** @var array{stream: resource, sha256: string, size: int, mime: string}|null $source */
        $source = null;

        try {
            foreach (LeaveRequest::query()
                ->select(['id', 'employee_id'])
                ->where('lampiran_path', $sourcePath)
                ->orderBy('id')
                ->cursor() as $leave) {
                $referenceCount++;
                $employeeId = (string) $leave->employee_id;
                if (! Str::isUuid($employeeId)) {
                    throw new \RuntimeException("Employee pada lampiran {$sourcePath} tidak memakai UUID valid.");
                }

                $allCanonical = $allCanonical && $this->isCanonicalLeaveAttachmentPath($sourcePath, $employeeId);
                $canonicalOwnerId ??= $employeeId;
                if ($canonicalOwnerId !== $employeeId) {
                    $allCanonical = false;
                }
            }

            if ($referenceCount === 0) {
                return;
            }
            if ($allCanonical && $canonicalOwnerId !== null) {
                $this->migrateCanonicalLeaveAttachment(
                    $public,
                    $private,
                    $sourcePath,
                    $canonicalOwnerId,
                    $referenceCount,
                    $execute,
                    $counts,
                );

                return;
            }
            if (! $this->isSafeLeaveAttachmentSourcePath($sourcePath)) {
                $counts['konflik'] += $referenceCount;
                $this->warn("konflik: path lampiran cuti legacy tidak aman atau formatnya tidak didukung: {$sourcePath}.");

                return;
            }
            if (! $public->exists($sourcePath)) {
                $counts['hilang'] += $referenceCount;
                $this->warn("hilang: lampiran cuti legacy {$sourcePath} tidak ditemukan pada disk publik.");

                return;
            }

            $source = $this->validatedLeaveAttachmentSource($public, $sourcePath);
            if (! $execute) {
                $counts['siap'] += $referenceCount;

                return;
            }

            $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
            $this->prepareLegacyMigrationTargets($sourcePath, $source['sha256'], $extension);

            /** @var array{task_id: string, reference_count: int} $result */
            $result = DB::transaction(function () use ($private, $sourcePath, $source): array {
                $intentCount = $this->lockPreparedMigrationTargets($sourcePath, $source['sha256']);
                [$lockedReferenceCount, $employeeCount] = $this->lockLegacySourceReferences($sourcePath);
                if ($lockedReferenceCount === 0
                    || $intentCount !== $employeeCount
                    || $this->migrationPlanSetDoesNotMatch($sourcePath, $source['sha256'])) {
                    throw new \RuntimeException('Set intent target tidak lagi cocok dengan referensi source yang dikunci.');
                }

                $updatedReferences = $this->writeAndAdoptPreparedTargets(
                    $private,
                    $sourcePath,
                    $source['sha256'],
                    $source['stream'],
                );
                if ($updatedReferences !== $lockedReferenceCount) {
                    throw new \RuntimeException('Jumlah referensi lampiran berubah saat migrasi berlangsung.');
                }

                $sourceTask = $this->recovery->scheduleDelete(
                    StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
                    'public',
                    $sourcePath,
                    null,
                    'public',
                    $sourcePath,
                    $source['sha256'],
                );

                return ['task_id' => $sourceTask->id, 'reference_count' => $lockedReferenceCount];
            });

            if (! $this->recovery->attempt($result['task_id'])) {
                $counts['konflik'] += $result['reference_count'];
                $this->warn("konflik: cleanup source publik {$sourcePath} tertunda pada manifest recovery.");

                return;
            }

            $counts['dipindahkan'] += $result['reference_count'];
        } catch (\Throwable $exception) {
            $counts['konflik'] += max(1, $referenceCount);
            $this->warn("konflik: migrasi lampiran cuti {$sourcePath} dibatalkan; {$exception->getMessage()}");
        } finally {
            if ($source !== null && is_resource($source['stream'])) {
                fclose($source['stream']);
            }
        }
    }

    /**
     * Memindai metadata proof dengan keyset stabil; perubahan document_path tidak boleh menggeser halaman berikutnya.
     *
     * @param  array<string, int>  $counts
     */
    private function migrateReferencedLeaveProofs(Filesystem $disk, bool $execute, array &$counts): void
    {
        $lastId = null;

        do {
            $query = LeaveProof::query()
                ->select(['id', 'leave_request_id', 'document_path', 'document_mime'])
                ->whereNotNull('document_path')
                ->where('document_path', '<>', '')
                ->orderBy('id')
                ->limit(self::LEAVE_PROOF_SCAN_BATCH_SIZE);
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $proofs = $query->get();
            foreach ($proofs as $proof) {
                $this->migrateLeaveProof($disk, $proof, $execute, $counts);
            }

            $lastId = $proofs->last()?->id;
        } while ($proofs->count() === self::LEAVE_PROOF_SCAN_BATCH_SIZE && $lastId !== null);
    }

    /**
     * Cutover dua fase menjaga source sampai metadata kanonis dan audit sudah commit.
     *
     * @param  array<string, int>  $counts
     */
    private function migrateLeaveProof(
        Filesystem $disk,
        LeaveProof $proof,
        bool $execute,
        array &$counts,
    ): void {
        $leaveRequestId = (string) $proof->leave_request_id;
        $sourcePath = (string) $proof->document_path;

        if ($this->recovery->isCanonicalLeaveProofPath($sourcePath, $leaveRequestId)) {
            try {
                $exists = $disk->exists($sourcePath);
            } catch (\Throwable) {
                // Kegagalan adapter bukan bukti file hilang dan detail storage tidak boleh bocor ke output deployment.
                $counts['konflik']++;
                $this->warn('konflik: keberadaan bukti cuti kanonis gagal diperiksa secara fail-closed.');

                return;
            }
            $counts[$exists ? 'sudah_privat' : 'hilang']++;
            if (! $exists) {
                $this->warn('hilang: bukti cuti kanonis tidak ditemukan.');
            }

            return;
        }

        if (! $this->recovery->isLegacyLeaveProofPath($sourcePath, $leaveRequestId)) {
            $counts['tidak_valid']++;
            $this->warn('tidak_valid: path bukti cuti legacy tidak memenuhi whitelist exact.');

            return;
        }
        try {
            $sourceExists = $disk->exists($sourcePath);
        } catch (\Throwable) {
            // Proof legacy tidak boleh diproses ketika keberadaan source gagal dibuktikan secara aman.
            $counts['tidak_valid']++;
            $this->warn('tidak_valid: keberadaan bukti cuti legacy gagal diperiksa secara fail-closed.');

            return;
        }
        if (! $sourceExists) {
            $counts['hilang']++;
            $this->warn('hilang: bukti cuti legacy tidak ditemukan pada storage privat.');

            return;
        }

        try {
            $source = $this->validatedLeaveProofSource($disk, $sourcePath);
        } catch (\Throwable) {
            $counts['tidak_valid']++;
            $this->warn('tidak_valid: bukti cuti legacy gagal validasi PDF secara fail-closed.');

            return;
        }

        try {
            if (! $execute) {
                $this->assertLegacyLeaveProofDryRunReady(
                    $disk,
                    $leaveRequestId,
                    $sourcePath,
                    $source['sha256'],
                    $source['size'],
                );
                $counts['siap']++;

                return;
            }

            $intent = $this->prepareLegacyLeaveProofMigrationTarget(
                $leaveRequestId,
                $sourcePath,
                $source['sha256'],
            );

            /** @var array{task_id: string} $result */
            $result = DB::transaction(function () use ($disk, $proof, $leaveRequestId, $sourcePath, $source, $intent): array {
                $lockedIntent = StorageRecoveryTask::query()
                    ->whereKey($intent->id)
                    ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                    ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                    ->where('category', StorageRecoveryService::CATEGORY_LEAVE_PROOF)
                    ->lockForUpdate()
                    ->sole();
                $lockedProof = LeaveProof::query()->whereKey($proof->id)->lockForUpdate()->sole();
                if ((string) $lockedProof->leave_request_id !== $leaveRequestId
                    || (string) $lockedProof->document_path !== $sourcePath
                    || ! $this->recovery->isLegacyLeaveProofPath($sourcePath, $leaveRequestId)
                    || ! $this->recovery->isCanonicalLeaveProofPath($lockedIntent->path, $leaveRequestId)) {
                    throw new \RuntimeException('Metadata proof berubah sebelum cutover dapat dikunci.');
                }

                $this->assertLeaveProofSourceUnchanged($disk, $sourcePath, $source['sha256']);
                $targetExists = $disk->exists($lockedIntent->path);
                if ($targetExists && ! hash_equals(
                    $source['sha256'],
                    BoundedStorageHasher::sha256(
                        $disk,
                        $lockedIntent->path,
                        self::LEAVE_PROOF_MAX_BYTES,
                        $source['size'],
                    ),
                )) {
                    throw new \RuntimeException('Target proof kanonis sudah berisi file dengan hash berbeda.');
                }
                if (! $targetExists && ! $this->writeBufferedStream($disk, $lockedIntent->path, $source['stream'])) {
                    throw new \RuntimeException('Penulisan target proof kanonis gagal.');
                }
                if (! hash_equals(
                    $source['sha256'],
                    BoundedStorageHasher::sha256(
                        $disk,
                        $lockedIntent->path,
                        self::LEAVE_PROOF_MAX_BYTES,
                        $source['size'],
                    ),
                )) {
                    throw new \RuntimeException('Verifikasi hash target proof kanonis gagal.');
                }
                $this->assertLeaveProofSourceUnchanged($disk, $sourcePath, $source['sha256']);

                $lockedProof->forceFill([
                    'document_path' => $lockedIntent->path,
                    'document_mime' => LeaveProofDocumentStorageService::MIME,
                ])->save();
                AuditService::logDatabaseUpgradeOrFail(
                    'UPDATE',
                    'LeaveProof',
                    $lockedProof->id,
                    [
                        'storage_contract' => 'legacy',
                        'document_available' => true,
                    ],
                    [
                        'storage_contract' => 'canonical_private',
                        'document_available' => true,
                        'document_mime' => LeaveProofDocumentStorageService::MIME,
                    ],
                );
                $this->recovery->markMigrationTargetAdopted($lockedIntent);
                $sourceTask = $this->recovery->scheduleDelete(
                    StorageRecoveryService::CATEGORY_LEAVE_PROOF_LEGACY_SOURCE,
                    'local',
                    $sourcePath,
                    $leaveRequestId,
                    'local',
                    $lockedIntent->path,
                    $source['sha256'],
                );

                return ['task_id' => $sourceTask->id];
            });

            if (! $this->recovery->attempt($result['task_id'])) {
                $counts['konflik']++;
                $this->warn('konflik: cleanup source bukti cuti legacy tertunda pada manifest recovery.');

                return;
            }

            $counts['dipindahkan']++;
        } catch (\Throwable) {
            $counts['konflik']++;
            $this->warn('konflik: migrasi bukti cuti dibatalkan secara fail-closed.');
        } finally {
            fclose($source['stream']);
        }
    }

    /** Dry-run hanya membaca intent durable dan byte target; tidak membuat UUID, row, atau file baru. */
    private function assertLegacyLeaveProofDryRunReady(
        Filesystem $disk,
        string $leaveRequestId,
        string $sourcePath,
        string $sha256,
        int $sourceSize,
    ): void {
        $prepared = $this->preparedLegacyLeaveProofMigrationTargets($leaveRequestId, $sourcePath);
        if ($prepared->count() > 1) {
            throw new \RuntimeException('Lebih dari satu intent proof legacy berada pada status prepared.');
        }
        if ($prepared->isEmpty()) {
            return;
        }

        $intent = $prepared->sole();
        $this->assertPreparedLegacyLeaveProofIntent($intent, $leaveRequestId, $sha256);
        if ($disk->exists($intent->path)
            && ! hash_equals(
                $sha256,
                BoundedStorageHasher::sha256(
                    $disk,
                    $intent->path,
                    self::LEAVE_PROOF_MAX_BYTES,
                    $sourceSize,
                ),
            )) {
            throw new \RuntimeException('Target proof prepared memiliki hash berbeda.');
        }
    }

    /** Intent target di-commit sebelum byte target ditulis sehingga crash dapat dipulihkan durable. */
    private function prepareLegacyLeaveProofMigrationTarget(
        string $leaveRequestId,
        string $sourcePath,
        string $sha256,
    ): StorageRecoveryTask {
        return DB::transaction(function () use ($leaveRequestId, $sourcePath, $sha256): StorageRecoveryTask {
            $prepared = $this->preparedLegacyLeaveProofMigrationTargets(
                $leaveRequestId,
                $sourcePath,
                lockForUpdate: true,
            );
            if ($prepared->count() > 1) {
                throw new \RuntimeException('Lebih dari satu intent proof legacy berada pada status prepared.');
            }
            if ($prepared->isNotEmpty()) {
                $intent = $prepared->sole();
                $this->assertPreparedLegacyLeaveProofIntent($intent, $leaveRequestId, $sha256);

                return $intent;
            }

            $targetPath = 'leave-proofs/'.$leaveRequestId.'/'.Str::uuid().'.pdf';

            return $this->recovery->prepareLeaveProofMigrationTarget(
                $targetPath,
                $leaveRequestId,
                $sourcePath,
                $sha256,
            );
        });
    }

    /**
     * Mencari intent berdasarkan source, termasuk intent dengan hash stale yang wajib menjadi konflik.
     *
     * @return Collection<int, StorageRecoveryTask>
     */
    private function preparedLegacyLeaveProofMigrationTargets(
        string $leaveRequestId,
        string $sourcePath,
        bool $lockForUpdate = false,
    ): Collection {
        $query = StorageRecoveryTask::query()
            ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
            ->where('status', StorageRecoveryTask::STATUS_PREPARED)
            ->where('category', StorageRecoveryService::CATEGORY_LEAVE_PROOF)
            ->where('disk', 'local')
            ->where('owner_id', $leaveRequestId)
            ->where('source_disk', 'local')
            ->where('source_path', $sourcePath)
            ->limit(2);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /** Intent lama tidak boleh diam-diam dipakai untuk source yang hash atau kontraknya sudah berubah. */
    private function assertPreparedLegacyLeaveProofIntent(
        StorageRecoveryTask $intent,
        string $leaveRequestId,
        string $sha256,
    ): void {
        if (! $this->recovery->isCanonicalLeaveProofPath($intent->path, $leaveRequestId)
            || ! is_string($intent->sha256)
            || ! hash_equals($sha256, $intent->sha256)) {
            throw new \RuntimeException('Intent proof prepared tidak cocok dengan source terkini.');
        }
    }

    /** Source divalidasi ulang di dalam critical section sebelum write dan tepat sebelum metadata switch. */
    private function assertLeaveProofSourceUnchanged(
        Filesystem $disk,
        string $sourcePath,
        string $expectedSha256,
    ): void {
        $current = $this->validatedLeaveProofSource($disk, $sourcePath);
        try {
            if (! hash_equals($expectedSha256, $current['sha256'])) {
                throw new \RuntimeException('Source proof berubah selama cutover.');
            }
        } finally {
            fclose($current['stream']);
        }
    }

    /**
     * Memvalidasi magic PDF, MIME, ukuran, dan hash sambil membatasi memori dengan buffer stream.
     *
     * @return array{stream: resource, sha256: string, size: int, mime: string}
     */
    private function validatedLeaveProofSource(Filesystem $disk, string $path): array
    {
        $size = $disk->size($path);
        if ($size <= 0 || $size > self::LEAVE_PROOF_MAX_BYTES) {
            throw new \RuntimeException('Ukuran proof kosong atau melampaui batas 10 MB.');
        }

        $input = $disk->readStream($path);
        if (! is_resource($input)) {
            throw new \RuntimeException('Proof legacy tidak dapat dibaca sebagai stream.');
        }
        $buffer = tmpfile();
        if (! is_resource($buffer)) {
            fclose($input);

            throw new \RuntimeException('Buffer temporer proof tidak tersedia.');
        }

        $hash = hash_init('sha256');
        $sample = '';
        $bytes = 0;
        try {
            while (! feof($input)) {
                $chunk = fread($input, self::STREAM_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new \RuntimeException('Pembacaan stream proof legacy gagal.');
                }
                if ($chunk === '') {
                    if (feof($input)) {
                        break;
                    }

                    throw new \RuntimeException('Stream proof legacy berhenti sebelum selesai.');
                }

                $bytes += strlen($chunk);
                if ($bytes > self::LEAVE_PROOF_MAX_BYTES) {
                    throw new \RuntimeException('Ukuran aktual proof melampaui batas 10 MB.');
                }
                if (strlen($sample) < self::STREAM_CHUNK_BYTES) {
                    $sample .= substr($chunk, 0, self::STREAM_CHUNK_BYTES - strlen($sample));
                }
                hash_update($hash, $chunk);
                $this->writeAll($buffer, $chunk);
            }
        } catch (\Throwable $exception) {
            fclose($buffer);

            throw $exception;
        } finally {
            fclose($input);
        }

        if ($bytes !== $size) {
            fclose($buffer);

            throw new \RuntimeException('Ukuran metadata dan stream proof tidak konsisten.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($sample);
        if (! str_starts_with($sample, '%PDF-') || $mime !== LeaveProofDocumentStorageService::MIME) {
            fclose($buffer);

            throw new \RuntimeException('Magic atau MIME proof legacy bukan PDF.');
        }

        rewind($buffer);

        return [
            'stream' => $buffer,
            'sha256' => hash_final($hash),
            'size' => $bytes,
            'mime' => $mime,
        ];
    }

    /**
     * Membatasi migrasi destruktif pada namespace cuti agar file publik domain lain tidak ikut terhapus.
     */
    private function isSafeLeaveAttachmentSourcePath(string $path): bool
    {
        return $this->recovery->isSafeLegacyLeaveAttachmentPath($path);
    }

    private function isCanonicalLeaveAttachmentPath(string $path, string $employeeId): bool
    {
        return $this->recovery->isCanonicalLeaveAttachmentPath($path, $employeeId);
    }

    /**
     * Menginventarisasi hanya namespace dokumen pegawai legacy; foto dan file publik non-dokumen dibiarkan.
     *
     * @return iterable<string>
     */
    private function orphanedPublicDocumentPaths(Filesystem $public): iterable
    {
        foreach ($this->publicFilePaths($public) as $rawPath) {
            $path = str_replace('\\', '/', ltrim($rawPath, '/\\'));
            if ($this->isLegacyEmployeeDocumentPath($path) && ! $this->isReferencedDocumentPath($path)) {
                yield $path;
            }
        }
    }

    /** Storage produksi memakai DirectoryListing lazy; fallback allFiles hanya untuk adapter test sederhana. */
    private function publicFilePaths(Filesystem $public): iterable
    {
        if ($public instanceof FilesystemAdapter) {
            foreach ($public->getDriver()->listContents('', true) as $attributes) {
                if ($attributes->isFile()) {
                    yield $attributes->path();
                }
            }

            return;
        }

        foreach ($public->allFiles() as $path) {
            yield $path;
        }
    }

    private function isReferencedDocumentPath(string $path): bool
    {
        foreach (self::DOCUMENT_REFERENCE_SOURCES as [$table, $column]) {
            if (DB::table($table)->where($column, $path)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function isLegacyEmployeeDocumentPath(string $path): bool
    {
        foreach (self::LEGACY_DOCUMENT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        $firstSegment = explode('/', $path, 2)[0];

        // Upload dokumen lama memakai UUID pegawai sebagai folder paling atas.
        return Str::isUuid($firstSegment) && str_contains($path, '/');
    }

    private function quarantineOrphan(Filesystem $public, Filesystem $private, string $path): bool
    {
        $quarantinePath = self::ORPHAN_QUARANTINE_PREFIX.'/'.$path;
        $privateExists = $private->exists($quarantinePath);
        if ($privateExists && ! $this->sameContentsAt($public, $path, $private, $quarantinePath)) {
            $this->warn("konflik: karantina {$quarantinePath} memiliki isi berbeda; file publik dipertahankan.");

            return false;
        }

        if (! $privateExists) {
            if (! $this->copyAndVerify($public, $path, $private, $quarantinePath)) {
                if ($private->exists($quarantinePath)
                    && (! $private->delete($quarantinePath) || $private->exists($quarantinePath))) {
                    $this->warn("konflik: karantina parsial {$quarantinePath} gagal dibersihkan.");
                }
                $this->warn("konflik: verifikasi karantina gagal untuk {$path}; file publik dipertahankan.");

                return false;
            }
        }

        if (! $public->delete($path) || $public->exists($path)) {
            $this->warn("konflik: file publik tanpa referensi {$path} gagal dihapus setelah karantina.");

            return false;
        }

        return true;
    }

    private function sameContentsAt(
        Filesystem $source,
        string $sourcePath,
        Filesystem $target,
        string $targetPath,
    ): bool {
        try {
            return $source->size($sourcePath) === $target->size($targetPath)
                && hash_equals($this->hashPath($source, $sourcePath), $this->hashPath($target, $targetPath));
        } catch (\Throwable) {
            return false;
        }
    }

    /** Menyalin berkas umum dengan stream lalu membandingkan ukuran dan SHA-256 tanpa memuat seluruh byte. */
    private function copyAndVerify(
        Filesystem $source,
        string $sourcePath,
        Filesystem $target,
        string $targetPath,
    ): bool {
        $stream = $source->readStream($sourcePath);
        if (! is_resource($stream)) {
            return false;
        }

        try {
            return $target->writeStream($targetPath, $stream)
                && $this->sameContentsAt($source, $sourcePath, $target, $targetPath);
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Memvalidasi kontrak upload lampiran sambil membuat buffer temporer dan hash secara chunked.
     * Batas 10 MB dicek dari metadata sebelum stream dibaca untuk menahan penggunaan resource.
     *
     * @return array{stream: resource, sha256: string, size: int, mime: string}
     */
    private function validatedLeaveAttachmentSource(Filesystem $disk, string $path): array
    {
        $size = $disk->size($path);
        if ($size <= 0 || $size > self::LEAVE_ATTACHMENT_MAX_BYTES) {
            throw new \RuntimeException('Ukuran lampiran cuti legacy melampaui kontrak upload 10 MB atau kosong.');
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, self::LEAVE_ATTACHMENT_EXTENSIONS, true)) {
            throw new \RuntimeException('Ekstensi lampiran cuti legacy tidak didukung.');
        }

        $input = $disk->readStream($path);
        if (! is_resource($input)) {
            throw new \RuntimeException('Lampiran cuti legacy tidak dapat dibaca sebagai stream.');
        }

        $buffer = tmpfile();
        if (! is_resource($buffer)) {
            fclose($input);

            throw new \RuntimeException('Buffer temporer migrasi lampiran tidak tersedia.');
        }

        $hash = hash_init('sha256');
        $sample = '';
        $bytes = 0;

        try {
            while (! feof($input)) {
                $chunk = fread($input, self::STREAM_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new \RuntimeException('Pembacaan stream lampiran cuti legacy gagal.');
                }
                if ($chunk === '') {
                    if (feof($input)) {
                        break;
                    }

                    throw new \RuntimeException('Stream lampiran berhenti sebelum seluruh byte terbaca.');
                }

                $bytes += strlen($chunk);
                if ($bytes > self::LEAVE_ATTACHMENT_MAX_BYTES) {
                    throw new \RuntimeException('Ukuran aktual lampiran cuti legacy melampaui batas 10 MB.');
                }
                if (strlen($sample) < self::STREAM_CHUNK_BYTES) {
                    $sample .= substr($chunk, 0, self::STREAM_CHUNK_BYTES - strlen($sample));
                }
                hash_update($hash, $chunk);
                $this->writeAll($buffer, $chunk);
            }
        } catch (\Throwable $exception) {
            fclose($buffer);

            throw $exception;
        } finally {
            fclose($input);
        }

        if ($bytes !== $size) {
            fclose($buffer);

            throw new \RuntimeException('Ukuran metadata dan stream lampiran cuti legacy tidak konsisten.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($sample);
        $allowedMimes = self::LEAVE_ATTACHMENT_MIME_BY_EXTENSION[$extension] ?? [];
        if (! is_string($mime) || ! in_array($mime, $allowedMimes, true)) {
            fclose($buffer);

            throw new \RuntimeException('MIME lampiran cuti legacy tidak sesuai dengan ekstensi file.');
        }

        rewind($buffer);

        return [
            'stream' => $buffer,
            'sha256' => hash_final($hash),
            'size' => $bytes,
            'mime' => $mime,
        ];
    }

    /** Menulis ulang buffer yang sama ke beberapa target employee tanpa membangun string besar. */
    private function writeBufferedStream(Filesystem $disk, string $path, mixed $stream): bool
    {
        if (! is_resource($stream) || ! rewind($stream)) {
            return false;
        }

        return $disk->writeStream($path, $stream);
    }

    private function hashPath(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Berkas storage tidak dapat dibaca untuk verifikasi hash.');
        }

        $hash = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::STREAM_CHUNK_BYTES);
                if ($chunk === false) {
                    throw new \RuntimeException('Pembacaan storage untuk verifikasi hash gagal.');
                }
                if ($chunk !== '') {
                    hash_update($hash, $chunk);
                }
            }

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);
        while ($offset < $length) {
            $written = fwrite($stream, substr($contents, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Buffer temporer migrasi tidak dapat ditulis lengkap.');
            }
            $offset += $written;
        }
    }
}
