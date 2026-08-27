<?php

namespace App\Services;

use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageDocument;
use App\Models\StorageRecoveryTask;
use App\Support\Cuti\LeaveProofPathContract;
use App\Support\Storage\BoundedStorageHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class StorageRecoveryService
{
    public const CATEGORY_LEAVE_ATTACHMENT = 'leave_attachment';

    public const CATEGORY_LEAVE_USAGE_DOCUMENT = 'leave_usage_document';

    public const CATEGORY_LEAVE_PROOF = 'leave_proof';

    public const CATEGORY_LEAVE_PROOF_LEGACY_SOURCE = 'leave_proof_legacy_source';

    public const CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY = 'leave_attachment_public_copy';

    public const DEFERRED_REFERENCE_ERROR = 'Cleanup ditunda karena path masih direferensikan.';

    public const DEFERRED_ACTIVE_CREATION_ERROR = 'Cleanup ditunda karena intent pembuatan masih dalam masa lease.';

    public const CREATION_TARGET_GRACE_SECONDS = 60;

    private const ALLOWED_ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    private const ALLOWED_USAGE_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    private const LEAVE_ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Mencatat cleanup sebelum dicoba agar kegagalan storage tidak berubah menjadi orphan tanpa jejak.
     */
    public function scheduleDelete(
        string $category,
        string $disk,
        string $path,
        ?string $ownerId = null,
        ?string $sourceDisk = null,
        ?string $sourcePath = null,
        ?string $sha256 = null,
    ): StorageRecoveryTask {
        $this->assertValidDeleteTarget($category, $disk, $path, $ownerId);
        $this->assertValidOptionalSha256(
            $sha256,
            required: in_array($category, [
                self::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY,
                self::CATEGORY_LEAVE_PROOF_LEGACY_SOURCE,
            ], true),
        );
        if ($category === self::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY
            && ($sourceDisk !== LeaveRequest::ATTACHMENT_STORAGE_DISK || $sourcePath !== $path)) {
            throw new RuntimeException('Counterpart privat salinan publik kanonis tidak valid.');
        }
        if ($category === self::CATEGORY_LEAVE_PROOF_LEGACY_SOURCE
            && ($sourceDisk !== 'local' || ! is_string($sourcePath)
                || ! $this->isCanonicalLeaveProofPath($sourcePath, $ownerId))) {
            throw new RuntimeException('Counterpart kanonis bukti cuti legacy tidak valid.');
        }

        try {
            $task = $this->persistIdempotently([
                'operation' => StorageRecoveryTask::OPERATION_DELETE,
                'status' => StorageRecoveryTask::STATUS_PENDING,
                'category' => $category,
                'disk' => $disk,
                'path' => $path,
                'owner_id' => $ownerId,
                'source_disk' => $sourceDisk,
                'source_path' => $sourcePath,
                'sha256' => $sha256,
                'attempts' => 0,
                'last_error' => null,
                'last_attempted_at' => null,
                'completed_at' => null,
            ]);

            // Path dapat muncul kembali akibat restore operasional; task completed yang sama harus dapat dipakai ulang.
            if ($task->status === StorageRecoveryTask::STATUS_COMPLETED) {
                StorageRecoveryTask::query()
                    ->whereKey($task->id)
                    ->where('status', StorageRecoveryTask::STATUS_COMPLETED)
                    ->update([
                        'status' => StorageRecoveryTask::STATUS_PENDING,
                        'last_error' => null,
                        'last_attempted_at' => null,
                        'completed_at' => null,
                        'updated_at' => now(),
                    ]);
                $task->refresh();
            }

            return $task;
        } catch (Throwable $exception) {
            // Jika ledger sendiri tidak dapat ditulis, error utama tetap dilempar dan operator menerima sinyal kritis.
            try {
                Log::critical('Manifest cleanup storage sensitif gagal ditulis.', [
                    'category' => $category,
                    'disk' => $disk,
                    'error' => $this->sanitizeError($exception->getMessage(), $path, $sourcePath),
                ]);
            } catch (Throwable) {
                // Pelaporan sekunder tidak boleh mengganti exception persistence yang harus terlihat caller.
            }

            throw $exception;
        }
    }

    /**
     * Menyiapkan intent cleanup target dalam commit terpisah sebelum migrator menulis byte apa pun.
     */
    public function prepareMigrationTarget(
        string $disk,
        string $path,
        string $ownerId,
        string $sourceDisk,
        string $sourcePath,
        string $sha256,
    ): StorageRecoveryTask {
        if ($disk !== LeaveRequest::ATTACHMENT_STORAGE_DISK
            || ! $this->isCanonicalLeaveAttachmentPath($path, $ownerId)
            || $sourceDisk !== 'public'
            || (! $this->isSafeLegacyLeaveAttachmentPath($sourcePath)
                && ! $this->isCanonicalLeaveAttachmentPath($sourcePath, $ownerId))) {
            throw new RuntimeException('Intent target migrasi lampiran tidak memenuhi kontrak storage.');
        }
        $this->assertValidOptionalSha256($sha256, required: true);

        $task = $this->persistWithCriticalSignal([
            'operation' => StorageRecoveryTask::OPERATION_MIGRATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => self::CATEGORY_LEAVE_ATTACHMENT,
            'disk' => $disk,
            'path' => $path,
            'owner_id' => $ownerId,
            'source_disk' => $sourceDisk,
            'source_path' => $sourcePath,
            'sha256' => $sha256,
            'attempts' => 0,
            'last_error' => null,
            'last_attempted_at' => null,
            'completed_at' => null,
        ], $path, $sourcePath);

        // Intent yang sudah dibersihkan recovery dapat dipakai ulang pada rerun dengan identitas persis sama.
        if ($task->status === StorageRecoveryTask::STATUS_COMPLETED) {
            StorageRecoveryTask::query()
                ->whereKey($task->id)
                ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                ->where('status', StorageRecoveryTask::STATUS_COMPLETED)
                ->update([
                    'status' => StorageRecoveryTask::STATUS_PREPARED,
                    'last_error' => null,
                    'last_attempted_at' => null,
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);
            $task->refresh();
        }

        if ($task->operation !== StorageRecoveryTask::OPERATION_MIGRATION_TARGET
            || $task->status !== StorageRecoveryTask::STATUS_PREPARED) {
            throw new RuntimeException('Intent target migrasi tidak lagi berada pada status prepared.');
        }

        return $task;
    }

    /** Menyiapkan intent khusus cutover proof legacy tanpa membuka format legacy pada validator runtime. */
    public function prepareLeaveProofMigrationTarget(
        string $path,
        string $leaveRequestId,
        string $sourcePath,
        string $sha256,
    ): StorageRecoveryTask {
        if (! $this->isCanonicalLeaveProofPath($path, $leaveRequestId)
            || ! $this->isLegacyLeaveProofPath($sourcePath, $leaveRequestId)) {
            throw new RuntimeException('Intent target migrasi bukti cuti tidak memenuhi kontrak storage.');
        }
        $this->assertValidOptionalSha256($sha256, required: true);

        $task = $this->persistWithCriticalSignal([
            'operation' => StorageRecoveryTask::OPERATION_MIGRATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => self::CATEGORY_LEAVE_PROOF,
            'disk' => 'local',
            'path' => $path,
            'owner_id' => $leaveRequestId,
            'source_disk' => 'local',
            'source_path' => $sourcePath,
            'sha256' => $sha256,
            'attempts' => 0,
            'last_error' => null,
            'last_attempted_at' => null,
            'completed_at' => null,
        ], $path, $sourcePath);

        if ($task->status === StorageRecoveryTask::STATUS_COMPLETED) {
            StorageRecoveryTask::query()
                ->whereKey($task->id)
                ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                ->where('status', StorageRecoveryTask::STATUS_COMPLETED)
                ->update([
                    'status' => StorageRecoveryTask::STATUS_PREPARED,
                    'last_error' => null,
                    'last_attempted_at' => null,
                    'completed_at' => null,
                    'updated_at' => now(),
                ]);
            $task->refresh();
        }

        if ($task->operation !== StorageRecoveryTask::OPERATION_MIGRATION_TARGET
            || $task->status !== StorageRecoveryTask::STATUS_PREPARED) {
            throw new RuntimeException('Intent target migrasi bukti cuti tidak lagi berstatus prepared.');
        }

        return $task;
    }

    /**
     * Mencatat target proof baru pada commit independen sebelum storage menerima byte sensitif.
     */
    public function prepareLeaveProofCreationTarget(
        string $path,
        string $leaveRequestId,
        string $sha256,
    ): StorageRecoveryTask {
        if (! $this->isCanonicalLeaveProofPath($path, $leaveRequestId)) {
            throw new RuntimeException('Intent target pembuatan bukti cuti tidak memenuhi kontrak storage.');
        }

        return $this->prepareCreationTarget(
            self::CATEGORY_LEAVE_PROOF,
            'local',
            $path,
            $leaveRequestId,
            $sha256,
            'Manifest target pembuatan bukti cuti gagal ditulis.',
        );
    }

    /** Mencatat manifest committed sebelum byte dokumen pemakaian cuti ditulis ke private disk. */
    public function prepareLeaveUsageDocumentCreationTarget(
        string $path,
        string $employeeId,
        string $sha256,
    ): StorageRecoveryTask {
        if (! $this->isCanonicalLeaveUsagePath($path, $employeeId)) {
            throw new RuntimeException('Intent target dokumen pemakaian cuti tidak memenuhi kontrak storage.');
        }

        return $this->prepareCreationTarget(
            self::CATEGORY_LEAVE_USAGE_DOCUMENT,
            LeaveUsageDocument::STORAGE_DISK,
            $path,
            $employeeId,
            $sha256,
            'Manifest target dokumen pemakaian cuti gagal ditulis.',
        );
    }

    /** Mencatat manifest committed sebelum byte lampiran pengajuan ditulis ke private disk. */
    public function prepareLeaveAttachmentCreationTarget(
        string $path,
        string $employeeId,
        string $sha256,
    ): StorageRecoveryTask {
        if (! $this->isCanonicalLeaveAttachmentPath($path, $employeeId)) {
            throw new RuntimeException('Intent target lampiran pengajuan tidak memenuhi kontrak storage.');
        }

        return $this->prepareCreationTarget(
            self::CATEGORY_LEAVE_ATTACHMENT,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
            $employeeId,
            $sha256,
            'Manifest target lampiran pengajuan gagal ditulis.',
        );
    }

    /**
     * Memeriksa apakah file privat kanonis lama masih perlu diadopsi ke manifest integritas.
     * Pemeriksaan ini read-only agar dry-run command tidak mengubah ledger recovery.
     */
    public function canonicalLeaveAttachmentNeedsAdoption(
        string $path,
        string $employeeId,
        string $sha256,
    ): bool {
        $this->assertCanonicalLeaveAttachmentUpgradeInput($path, $employeeId, $sha256);

        return DB::transaction(function () use ($path, $employeeId, $sha256): bool {
            $this->assertExactLeaveAttachmentReferences($path, $employeeId, lock: false);
            $task = $this->canonicalLeaveAttachmentTarget($path, $employeeId, $sha256, lock: false);

            return $task === null || $task->status === StorageRecoveryTask::STATUS_PREPARED;
        });
    }

    /**
     * Mengadopsi file privat lama hanya setelah path, owner, referensi, dan hash target cocok persis.
     * Intent creation-target tetap durable agar crash sebelum adoption dapat dilanjutkan secara idempoten.
     */
    public function adoptReferencedCanonicalLeaveAttachment(
        string $path,
        string $employeeId,
        string $sha256,
    ): StorageRecoveryTask {
        $this->assertCanonicalLeaveAttachmentUpgradeInput($path, $employeeId, $sha256);

        return DB::transaction(function () use ($path, $employeeId, $sha256): StorageRecoveryTask {
            $this->assertExactLeaveAttachmentReferences($path, $employeeId, lock: true);
            $task = $this->canonicalLeaveAttachmentTarget($path, $employeeId, $sha256, lock: true);

            if ($task?->status === StorageRecoveryTask::STATUS_ADOPTED) {
                if (! $this->storagePathMatchesHash($task->disk, $task->path, $sha256)) {
                    throw new RuntimeException('Target lampiran kanonis tidak lagi cocok dengan hash manifest.');
                }

                return $task;
            }

            if ($task?->operation === StorageRecoveryTask::OPERATION_MIGRATION_TARGET) {
                $this->markMigrationTargetAdopted($task);

                return $task->fresh();
            }

            $task ??= $this->prepareLeaveAttachmentCreationTarget($path, $employeeId, $sha256);
            $this->markLeaveAttachmentCreationTargetAdopted($task->id, $employeeId, $path);

            return $task->fresh();
        });
    }

    /** Main transaction hanya boleh mengadopsi row PREPARED yang sudah dikunci caller. */
    public function markMigrationTargetAdopted(StorageRecoveryTask $task): void
    {
        $this->assertValidMigrationTarget($task);
        $updated = StorageRecoveryTask::query()
            ->whereKey($task->id)
            ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
            ->where('status', StorageRecoveryTask::STATUS_PREPARED)
            ->update([
                'status' => StorageRecoveryTask::STATUS_ADOPTED,
                'last_error' => null,
                'last_attempted_at' => now(),
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('Intent target migrasi berubah sebelum dapat diadopsi.');
        }

        $task->refresh();
    }

    /**
     * Memverifikasi lampiran privat terhadap manifest final creation atau migration target.
     * Manifest dikunci selama pembacaan bounded agar file berubah atau hilang selalu fail-closed.
     */
    public function verifyAdoptedLeaveAttachmentArtifact(string $employeeId, string $path): bool
    {
        if (! $this->isCanonicalLeaveAttachmentPath($path, $employeeId)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($employeeId, $path): bool {
                $task = StorageRecoveryTask::query()
                    ->where('owner_id', $employeeId)
                    ->where('category', self::CATEGORY_LEAVE_ATTACHMENT)
                    ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
                    ->where('status', StorageRecoveryTask::STATUS_ADOPTED)
                    ->whereIn('operation', [
                        StorageRecoveryTask::OPERATION_CREATION_TARGET,
                        StorageRecoveryTask::OPERATION_MIGRATION_TARGET,
                    ])
                    ->where('path', $path)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($task === null) {
                    return false;
                }

                try {
                    if ($task->operation === StorageRecoveryTask::OPERATION_CREATION_TARGET) {
                        $this->assertValidCreationTarget($task);
                    } else {
                        $this->assertValidMigrationTarget($task);
                    }
                } catch (Throwable) {
                    $this->markManualReview($task, 'Manifest lampiran cuti yang diadopsi tidak valid.');

                    return false;
                }

                if (! is_string($task->sha256)
                    || ! $this->storagePathMatchesHash($task->disk, $task->path, $task->sha256)) {
                    $this->markManualReview($task, 'Lampiran cuti hilang atau berubah setelah diadopsi.');

                    return false;
                }

                return true;
            });
        } catch (Throwable $exception) {
            // Kegagalan database/storage wajib menutup unduhan tanpa membocorkan path lampiran privat.
            try {
                Log::critical('Integritas lampiran cuti gagal diverifikasi.', [
                    'employee_id' => $employeeId,
                    'error_type' => $exception::class,
                ]);
            } catch (Throwable) {
                // Pelaporan sekunder tidak boleh mengubah hasil verifikasi yang harus tetap fail-closed.
            }

            return false;
        }
    }

    /** Menandai target proof sah hanya setelah metadata committed dan masih mereferensikan path yang sama. */
    public function markLeaveProofCreationTargetAdopted(
        string $taskId,
        string $leaveRequestId,
        string $path,
    ): void {
        $this->markCreationTargetAdopted(
            $taskId,
            self::CATEGORY_LEAVE_PROOF,
            $leaveRequestId,
            $path,
        );
    }

    /**
     * Memverifikasi artifact proof terhadap hash manifest final yang sudah diadopsi.
     * Row lock menjaga status manifest stabil selama pembacaan bounded dan mengarantina mismatch secara atomik.
     */
    public function verifyAdoptedLeaveProofArtifact(string $leaveRequestId, string $path): bool
    {
        if (! $this->isCanonicalLeaveProofPath($path, $leaveRequestId)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($leaveRequestId, $path): bool {
                $task = StorageRecoveryTask::query()
                    ->where('owner_id', $leaveRequestId)
                    ->where('category', self::CATEGORY_LEAVE_PROOF)
                    ->where('disk', 'local')
                    ->where('status', StorageRecoveryTask::STATUS_ADOPTED)
                    ->whereIn('operation', [
                        StorageRecoveryTask::OPERATION_CREATION_TARGET,
                        StorageRecoveryTask::OPERATION_MIGRATION_TARGET,
                    ])
                    ->where('path', $path)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($task === null) {
                    return false;
                }

                try {
                    if ($task->operation === StorageRecoveryTask::OPERATION_CREATION_TARGET) {
                        $this->assertValidCreationTarget($task);
                    } else {
                        $this->assertValidMigrationTarget($task);
                    }
                } catch (Throwable) {
                    $this->markManualReview($task, 'Manifest artifact bukti cuti yang diadopsi tidak valid.');

                    return false;
                }

                if (! is_string($task->sha256)
                    || ! $this->storagePathMatchesHash($task->disk, $task->path, $task->sha256)) {
                    $this->markManualReview($task, 'Artifact bukti cuti hilang atau berubah setelah diadopsi.');

                    return false;
                }

                return true;
            });
        } catch (Throwable $exception) {
            // Kegagalan database/storage tetap menutup unduhan tanpa membocorkan path dokumen privat.
            try {
                Log::critical('Integritas artifact bukti cuti gagal diverifikasi.', [
                    'leave_request_id' => $leaveRequestId,
                    'error_type' => $exception::class,
                ]);
            } catch (Throwable) {
                // Pelaporan sekunder tidak boleh mengubah hasil verifikasi yang harus tetap fail-closed.
            }

            return false;
        }
    }

    /**
     * Memverifikasi dokumen pemakaian terhadap manifest ADOPTED agar byte privat yang berubah
     * setelah commit tidak kembali dibagikan melalui riwayat manual atau rekonsiliasi tahunan.
     */
    public function verifyAdoptedLeaveUsageDocumentArtifact(string $employeeId, string $path): bool
    {
        if (! $this->isCanonicalLeaveUsagePath($path, $employeeId)) {
            return false;
        }

        try {
            return DB::transaction(function () use ($employeeId, $path): bool {
                $task = StorageRecoveryTask::query()
                    ->where('owner_id', $employeeId)
                    ->where('category', self::CATEGORY_LEAVE_USAGE_DOCUMENT)
                    ->where('disk', LeaveUsageDocument::STORAGE_DISK)
                    ->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
                    ->where('status', StorageRecoveryTask::STATUS_ADOPTED)
                    ->where('path', $path)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($task === null) {
                    return false;
                }

                try {
                    $this->assertValidCreationTarget($task);
                } catch (Throwable) {
                    $this->markManualReview($task, 'Manifest artifact dokumen pemakaian yang diadopsi tidak valid.');

                    return false;
                }

                if (! is_string($task->sha256)
                    || ! $this->storagePathMatchesHash($task->disk, $task->path, $task->sha256)) {
                    $this->markManualReview($task, 'Artifact dokumen pemakaian hilang atau berubah setelah diadopsi.');

                    return false;
                }

                return true;
            });
        } catch (Throwable $exception) {
            // Kegagalan database/storage wajib menutup unduhan tanpa membocorkan lokasi dokumen privat.
            try {
                Log::critical('Integritas artifact dokumen pemakaian gagal diverifikasi.', [
                    'employee_id' => $employeeId,
                    'error_type' => $exception::class,
                ]);
            } catch (Throwable) {
                // Pelaporan sekunder tidak boleh mengubah hasil verifikasi yang harus tetap fail-closed.
            }

            return false;
        }
    }

    /** Menandai dokumen pemakaian sah hanya setelah metadata append-only committed. */
    public function markLeaveUsageDocumentCreationTargetAdopted(
        string $taskId,
        string $employeeId,
        string $path,
    ): void {
        $this->markCreationTargetAdopted(
            $taskId,
            self::CATEGORY_LEAVE_USAGE_DOCUMENT,
            $employeeId,
            $path,
        );
    }

    /** Menandai lampiran sah hanya setelah pengajuan atau resubmit mereferensikannya. */
    public function markLeaveAttachmentCreationTargetAdopted(
        string $taskId,
        string $employeeId,
        string $path,
    ): void {
        $this->markCreationTargetAdopted(
            $taskId,
            self::CATEGORY_LEAVE_ATTACHMENT,
            $employeeId,
            $path,
        );
    }

    /**
     * Memproses satu task secara sinkron dan idempoten. Row lock mencegah dua processor
     * menghapus target yang sama bersamaan, sedangkan pemeriksaan referensi melindungi file bersama.
     */
    public function attempt(string $taskId): bool
    {
        return DB::transaction(function () use ($taskId): bool {
            $task = StorageRecoveryTask::query()->whereKey($taskId)->lockForUpdate()->firstOrFail();

            if ($task->status === StorageRecoveryTask::STATUS_COMPLETED) {
                return true;
            }

            if (in_array($task->operation, [
                StorageRecoveryTask::OPERATION_MIGRATION_TARGET,
                StorageRecoveryTask::OPERATION_CREATION_TARGET,
            ], true)
                && $task->status === StorageRecoveryTask::STATUS_ADOPTED) {
                return true;
            }

            $isPreparedMigrationTarget = $task->operation === StorageRecoveryTask::OPERATION_MIGRATION_TARGET
                && $task->status === StorageRecoveryTask::STATUS_PREPARED;
            $isPreparedCreationTarget = $task->operation === StorageRecoveryTask::OPERATION_CREATION_TARGET
                && $task->status === StorageRecoveryTask::STATUS_PREPARED;
            $isPendingDelete = $task->operation === StorageRecoveryTask::OPERATION_DELETE
                && $task->status === StorageRecoveryTask::STATUS_PENDING;
            if (! $isPreparedMigrationTarget && ! $isPreparedCreationTarget && ! $isPendingDelete) {
                return false;
            }

            try {
                if ($isPreparedMigrationTarget) {
                    $this->assertValidMigrationTarget($task);
                } elseif ($isPreparedCreationTarget) {
                    $this->assertValidCreationTarget($task);
                } else {
                    $this->assertValidDeleteTarget($task->category, $task->disk, $task->path, $task->owner_id);
                    $this->assertValidOptionalSha256($task->sha256);
                }
            } catch (Throwable $exception) {
                $this->markManualReview($task, $exception->getMessage());

                return false;
            }

            if ($isPreparedCreationTarget
                && $task->created_at !== null
                && $task->created_at->isAfter(now()->subSeconds(self::CREATION_TARGET_GRACE_SECONDS))) {
                $this->markRetryableAttempt($task, self::DEFERRED_ACTIVE_CREATION_ERROR);

                return false;
            }

            if ($this->isStillReferenced($task)) {
                if ($isPreparedCreationTarget) {
                    if (! $this->creationTargetMatchesPinnedHash($task)) {
                        $this->markManualReview(
                            $task,
                            'Target pembuatan yang direferensikan hilang atau berubah sejak intent dibuat.',
                        );

                        return false;
                    }

                    $this->markAdopted($task, countAttempt: true);

                    return true;
                }

                if ($isPreparedMigrationTarget) {
                    $this->markManualReview(
                        $task,
                        'Intent target prepared sudah direferensikan database sebelum diadopsi.',
                    );
                } else {
                    $this->markRetryableAttempt($task, self::DEFERRED_REFERENCE_ERROR);
                }

                return false;
            }

            try {
                $disk = Storage::disk($task->disk);
                // Status PREPARED hanya aman diselesaikan bila source terpin tetap utuh, termasuk saat target sudah hilang.
                if ($isPreparedMigrationTarget && ! $this->hasValidPreparedMigrationSource($task)) {
                    $this->markManualReview(
                        $task,
                        'Source migrasi hilang atau berubah; recovery target prepared memerlukan pemeriksaan manual.',
                    );

                    return false;
                }

                if (! $disk->exists($task->path)) {
                    // Delete storage tidak transaksional: retry pasca-crash tetap wajib membuktikan copy retained aman.
                    if (! $this->hasValidRetainedCounterpart($task)) {
                        $this->markManualReview($task, 'Counterpart privat migrasi hilang atau berubah.');

                        return false;
                    }

                    $this->markCompleted($task);

                    return true;
                }

                if ($task->sha256 !== null
                    && ! hash_equals(
                        $task->sha256,
                        BoundedStorageHasher::sha256($disk, $task->path, self::LEAVE_ATTACHMENT_MAX_BYTES),
                    )) {
                    $this->markManualReview($task, 'SHA-256 file berubah sejak intent cleanup dibuat.');

                    return false;
                }

                if (! $this->hasValidRetainedCounterpart($task)) {
                    $this->markManualReview($task, 'Counterpart privat migrasi hilang atau berubah.');

                    return false;
                }

                if (! $disk->delete($task->path)) {
                    $this->markRetryableAttempt($task, 'Storage mengembalikan hasil delete gagal.');

                    return false;
                }

                if ($disk->exists($task->path)) {
                    $this->markRetryableAttempt($task, 'File masih tersedia setelah operasi delete.');

                    return false;
                }

                $this->markCompleted($task);

                return true;
            } catch (Throwable $exception) {
                $this->markRetryableAttempt($task, $exception->getMessage());

                return false;
            }
        });
    }

    public function isCanonicalLeaveAttachmentPath(string $path, ?string $employeeId): bool
    {
        if (! is_string($employeeId) || ! Str::isUuid($employeeId)) {
            return false;
        }

        return $this->isExactUuidFilePath(
            $path,
            LeaveRequest::ATTACHMENT_PATH_PREFIX,
            $employeeId,
            self::ALLOWED_ATTACHMENT_EXTENSIONS,
        );
    }

    /** Legacy store menulis tepat satu filename di bawah folder public `cuti/`. */
    public function isSafeLegacyLeaveAttachmentPath(string $path): bool
    {
        $segments = explode('/', $path);
        if (count($segments) !== 2 || $segments[0] !== 'cuti') {
            return false;
        }

        $filename = $segments[1];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $this->isNormalizedRelativePath($path)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.(?:pdf|jpe?g|png)\z/D', $filename) === 1
            && in_array($extension, self::ALLOWED_ATTACHMENT_EXTENSIONS, true);
    }

    public function isCanonicalLeaveUsagePath(string $path, ?string $employeeId): bool
    {
        if (! is_string($employeeId) || ! Str::isUuid($employeeId)) {
            return false;
        }

        return $this->isExactUuidFilePath(
            $path,
            LeaveUsageDocument::PATH_PREFIX,
            $employeeId,
            self::ALLOWED_USAGE_EXTENSIONS,
        );
    }

    public function isCanonicalLeaveProofPath(string $path, ?string $leaveRequestId): bool
    {
        return is_string($leaveRequestId)
            && LeaveProofPathContract::isCanonical($leaveRequestId, $path);
    }

    /** Whitelist cutover hanya menerima bentuk lama persis `leave-proofs/{request}.pdf`. */
    public function isLegacyLeaveProofPath(string $path, ?string $leaveRequestId): bool
    {
        if (! is_string($leaveRequestId) || ! Str::isUuid($leaveRequestId)) {
            return false;
        }

        return $this->isNormalizedRelativePath($path)
            && $path === 'leave-proofs/'.strtolower($leaveRequestId).'.pdf';
    }

    /** Manifest harus durable sebelum write; row lock menjadi lease bila caller sudah berada dalam transaksi. */
    private function prepareCreationTarget(
        string $category,
        string $disk,
        string $path,
        string $ownerId,
        string $sha256,
        string $criticalMessage,
    ): StorageRecoveryTask {
        $this->assertValidOptionalSha256($sha256, required: true);
        $attributes = [
            'operation' => StorageRecoveryTask::OPERATION_CREATION_TARGET,
            'status' => StorageRecoveryTask::STATUS_PREPARED,
            'category' => $category,
            'disk' => $disk,
            'path' => $path,
            'owner_id' => $ownerId,
            'source_disk' => null,
            'source_path' => null,
            'sha256' => $sha256,
            'attempts' => 0,
            'last_error' => null,
            'last_attempted_at' => null,
            'completed_at' => null,
        ];

        try {
            $task = $this->persistIdempotently(
                $attributes,
                $this->independentRecoveryConnectionName(),
            );

            if (DB::transactionLevel() === 0) {
                return $task;
            }

            // Worker tidak boleh membersihkan file selama transaksi metadata masih berjalan.
            return StorageRecoveryTask::query()
                ->whereKey($task->id)
                ->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
                ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                ->lockForUpdate()
                ->firstOrFail();
        } catch (Throwable $exception) {
            try {
                Log::critical($criticalMessage, [
                    'category' => $category,
                    'disk' => $disk,
                    'error' => $this->sanitizeError($exception->getMessage(), $path, null),
                ]);
            } catch (Throwable) {
                // Pelaporan sekunder tidak boleh menutupi kegagalan manifest yang wajib terlihat caller.
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attributes */
    private function persistIdempotently(array $attributes, ?string $connectionName = null): StorageRecoveryTask
    {
        $identity = [
            $attributes['operation'],
            $attributes['category'],
            $attributes['disk'],
            $attributes['path'],
            $attributes['owner_id'],
            $attributes['source_disk'],
            $attributes['source_path'],
            $attributes['sha256'],
        ];
        $idempotencyKey = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $now = now();

        $model = new StorageRecoveryTask;
        if ($connectionName !== null) {
            $model->setConnection($connectionName);
        }
        $query = $model->newQuery();

        $query->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'idempotency_key' => $idempotencyKey,
            ...$attributes,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $query->where('idempotency_key', $idempotencyKey)->firstOrFail();
    }

    /**
     * PostgreSQL membutuhkan PDO kedua agar manifest tidak ikut rollback bersama transaksi approval.
     */
    private function independentRecoveryConnectionName(): ?string
    {
        $default = DB::connection();
        $transactionLevel = $default->transactionLevel();
        if ($transactionLevel < 1
            || $default->getDriverName() !== 'pgsql') {
            return null;
        }

        $connectionName = 'storage_recovery_intent';
        config(['database.connections.'.$connectionName => $default->getConfig()]);
        DB::purge($connectionName);

        return $connectionName;
    }

    private function assertValidDeleteTarget(string $category, string $disk, string $path, ?string $ownerId): void
    {
        $valid = match ($category) {
            self::CATEGORY_LEAVE_ATTACHMENT => ($disk === LeaveRequest::ATTACHMENT_STORAGE_DISK
                    && $this->isCanonicalLeaveAttachmentPath($path, $ownerId))
                || ($disk === 'public' && $ownerId === null && $this->isSafeLegacyLeaveAttachmentPath($path)),
            self::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY => $disk === 'public'
                && $this->isCanonicalLeaveAttachmentPath($path, $ownerId),
            self::CATEGORY_LEAVE_USAGE_DOCUMENT => $disk === LeaveUsageDocument::STORAGE_DISK
                && $this->isCanonicalLeaveUsagePath($path, $ownerId),
            self::CATEGORY_LEAVE_PROOF => $disk === 'local'
                && $this->isCanonicalLeaveProofPath($path, $ownerId),
            self::CATEGORY_LEAVE_PROOF_LEGACY_SOURCE => $disk === 'local'
                && $this->isLegacyLeaveProofPath($path, $ownerId),
            default => false,
        };

        if (! $valid) {
            throw new RuntimeException('Target cleanup storage tidak memenuhi kontrak path, disk, dan owner.');
        }
    }

    private function isStillReferenced(StorageRecoveryTask $task): bool
    {
        return match ($task->category) {
            self::CATEGORY_LEAVE_ATTACHMENT => LeaveRequest::query()
                ->where('lampiran_path', $task->path)
                ->when(
                    $task->disk === LeaveRequest::ATTACHMENT_STORAGE_DISK && is_string($task->owner_id),
                    fn ($query) => $query->where('employee_id', $task->owner_id),
                )
                ->exists(),
            self::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY => false,
            self::CATEGORY_LEAVE_USAGE_DOCUMENT => LeaveUsageDocument::query()
                ->where('path', $task->path)
                ->exists(),
            self::CATEGORY_LEAVE_PROOF => LeaveProof::query()
                ->where('document_path', $task->path)
                ->exists(),
            self::CATEGORY_LEAVE_PROOF_LEGACY_SOURCE => LeaveProof::query()
                ->where('document_path', $task->path)
                ->exists(),
            default => true,
        };
    }

    private function assertCanonicalLeaveAttachmentUpgradeInput(
        string $path,
        string $employeeId,
        string $sha256,
    ): void {
        if (! $this->isCanonicalLeaveAttachmentPath($path, $employeeId)) {
            throw new RuntimeException('Upgrade manifest lampiran tidak memenuhi kontrak path dan owner.');
        }

        $this->assertValidOptionalSha256($sha256, required: true);
    }

    /** Referensi dikunci secara keyset agar validasi owner tetap bounded pada path yang dipakai bersama. */
    private function assertExactLeaveAttachmentReferences(string $path, string $employeeId, bool $lock): void
    {
        $lastId = null;
        $count = 0;

        do {
            $query = LeaveRequest::query()
                ->select(['id', 'employee_id'])
                ->where('lampiran_path', $path)
                ->orderBy('id')
                ->limit(100);
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }
            if ($lock) {
                $query->lockForUpdate();
            }

            $rows = $query->get();
            foreach ($rows as $leave) {
                if ((string) $leave->employee_id !== $employeeId) {
                    throw new RuntimeException('Referensi lampiran kanonis tidak cocok dengan owner path.');
                }
                $count++;
            }
            $lastId = $rows->last()?->id;
        } while ($rows->count() === 100 && $lastId !== null);

        if ($count === 0) {
            throw new RuntimeException('Lampiran kanonis tidak direferensikan oleh pengajuan pemiliknya.');
        }
    }

    /**
     * Mengembalikan satu-satunya target yang aman dipakai ulang; status/hash yang ambigu ditolak fail-closed.
     */
    private function canonicalLeaveAttachmentTarget(
        string $path,
        string $employeeId,
        string $sha256,
        bool $lock,
    ): ?StorageRecoveryTask {
        $query = StorageRecoveryTask::query()
            ->where('category', self::CATEGORY_LEAVE_ATTACHMENT)
            ->where('disk', LeaveRequest::ATTACHMENT_STORAGE_DISK)
            ->where('path', $path)
            ->whereIn('operation', [
                StorageRecoveryTask::OPERATION_CREATION_TARGET,
                StorageRecoveryTask::OPERATION_MIGRATION_TARGET,
            ])
            ->orderBy('id')
            ->limit(2);
        if ($lock) {
            $query->lockForUpdate();
        }

        $tasks = $query->get();
        if ($tasks->count() > 1) {
            throw new RuntimeException('Lebih dari satu manifest target ditemukan untuk lampiran kanonis.');
        }

        $task = $tasks->first();
        if ($task === null) {
            return null;
        }
        if ($task->owner_id !== $employeeId || $task->sha256 !== $sha256) {
            throw new RuntimeException('Manifest target lampiran kanonis tidak cocok dengan owner atau hash file.');
        }

        if ($task->operation === StorageRecoveryTask::OPERATION_CREATION_TARGET) {
            $this->assertValidCreationTarget($task);
        } else {
            $this->assertValidMigrationTarget($task);
        }

        $validAdopted = $task->status === StorageRecoveryTask::STATUS_ADOPTED;
        $validPreparedCreationRetry = $task->operation === StorageRecoveryTask::OPERATION_CREATION_TARGET
            && $task->status === StorageRecoveryTask::STATUS_PREPARED;
        $validPreparedMigrationRetry = $task->operation === StorageRecoveryTask::OPERATION_MIGRATION_TARGET
            && $task->status === StorageRecoveryTask::STATUS_PREPARED
            && $task->source_disk === 'public'
            && $task->source_path === $path;
        if (! $validAdopted && ! $validPreparedCreationRetry && ! $validPreparedMigrationRetry) {
            throw new RuntimeException('Status manifest target lampiran kanonis tidak aman untuk upgrade.');
        }
        if ($validPreparedMigrationRetry
            && (! $this->storagePathMatchesHash('public', $path, $sha256)
                || ! $this->storagePathMatchesHash(LeaveRequest::ATTACHMENT_STORAGE_DISK, $path, $sha256))) {
            throw new RuntimeException('Source atau target migration prepared tidak cocok dengan hash manifest.');
        }

        return $task;
    }

    private function markCompleted(StorageRecoveryTask $task): void
    {
        $task->forceFill([
            'status' => StorageRecoveryTask::STATUS_COMPLETED,
            'attempts' => $task->attempts + 1,
            'last_error' => null,
            'last_attempted_at' => now(),
            'completed_at' => now(),
        ])->save();
    }

    private function markRetryableAttempt(StorageRecoveryTask $task, string $detail): void
    {
        $task->forceFill([
            'status' => $task->operation === StorageRecoveryTask::OPERATION_MIGRATION_TARGET
                || $task->operation === StorageRecoveryTask::OPERATION_CREATION_TARGET
                ? StorageRecoveryTask::STATUS_PREPARED
                : StorageRecoveryTask::STATUS_PENDING,
            'attempts' => $task->attempts + 1,
            'last_error' => $this->sanitizeError($detail, $task->path, $task->source_path),
            'last_attempted_at' => now(),
            'completed_at' => null,
        ])->save();
    }

    private function assertValidMigrationTarget(StorageRecoveryTask $task): void
    {
        $validAttachment = $task->category === self::CATEGORY_LEAVE_ATTACHMENT
            && $task->disk === LeaveRequest::ATTACHMENT_STORAGE_DISK
            && $this->isCanonicalLeaveAttachmentPath($task->path, $task->owner_id)
            && $task->source_disk === 'public'
            && is_string($task->source_path)
            && ($this->isSafeLegacyLeaveAttachmentPath($task->source_path)
                || $this->isCanonicalLeaveAttachmentPath($task->source_path, $task->owner_id));
        $validLeaveProof = $task->category === self::CATEGORY_LEAVE_PROOF
            && $task->disk === 'local'
            && $this->isCanonicalLeaveProofPath($task->path, $task->owner_id)
            && $task->source_disk === 'local'
            && is_string($task->source_path)
            && $this->isLegacyLeaveProofPath($task->source_path, $task->owner_id);

        if ($task->operation !== StorageRecoveryTask::OPERATION_MIGRATION_TARGET
            || (! $validAttachment && ! $validLeaveProof)) {
            throw new RuntimeException('Intent target migrasi tidak memenuhi kontrak keamanan.');
        }

        $this->assertValidOptionalSha256($task->sha256, required: true);
    }

    private function assertValidCreationTarget(StorageRecoveryTask $task): void
    {
        $validLeaveAttachment = $task->category === self::CATEGORY_LEAVE_ATTACHMENT
            && $task->disk === LeaveRequest::ATTACHMENT_STORAGE_DISK
            && $this->isCanonicalLeaveAttachmentPath($task->path, $task->owner_id);
        $validLeaveProof = $task->category === self::CATEGORY_LEAVE_PROOF
            && $task->disk === 'local'
            && $this->isCanonicalLeaveProofPath($task->path, $task->owner_id);
        $validLeaveUsageDocument = $task->category === self::CATEGORY_LEAVE_USAGE_DOCUMENT
            && $task->disk === LeaveUsageDocument::STORAGE_DISK
            && $this->isCanonicalLeaveUsagePath($task->path, $task->owner_id);

        if ($task->operation !== StorageRecoveryTask::OPERATION_CREATION_TARGET
            || (! $validLeaveAttachment && ! $validLeaveProof && ! $validLeaveUsageDocument)
            || $task->source_disk !== null
            || $task->source_path !== null) {
            throw new RuntimeException('Intent target pembuatan dokumen cuti tidak memenuhi kontrak keamanan.');
        }

        $this->assertValidOptionalSha256($task->sha256, required: true);
    }

    /** Adoption hanya sah bila task, owner, path, dan metadata append-only masih konsisten. */
    private function markCreationTargetAdopted(
        string $taskId,
        string $category,
        string $ownerId,
        string $path,
    ): void {
        $manualReviewError = DB::transaction(function () use ($taskId, $category, $ownerId, $path): ?string {
            $task = StorageRecoveryTask::query()->whereKey($taskId)->lockForUpdate()->firstOrFail();
            $this->assertValidCreationTarget($task);

            if ($task->category !== $category || $task->owner_id !== $ownerId || $task->path !== $path) {
                throw new RuntimeException('Intent target dokumen cuti tidak cocok dengan metadata committed.');
            }
            if ($task->status === StorageRecoveryTask::STATUS_ADOPTED) {
                return null;
            }
            if ($task->status !== StorageRecoveryTask::STATUS_PREPARED || ! $this->isStillReferenced($task)) {
                throw new RuntimeException('Intent target dokumen cuti belum dapat diadopsi.');
            }
            if (! $this->creationTargetMatchesPinnedHash($task)) {
                $detail = 'Target pembuatan yang direferensikan hilang atau berubah sejak intent dibuat.';
                $this->markManualReview($task, $detail);

                return $detail;
            }

            $this->markAdopted($task, countAttempt: false);

            return null;
        });

        // Error dilempar setelah transaksi agar keputusan manual review tidak ikut di-rollback.
        if ($manualReviewError !== null) {
            throw new RuntimeException($manualReviewError);
        }
    }

    /** Metadata committed hanya boleh mengadopsi byte yang masih cocok dengan hash intent. */
    private function creationTargetMatchesPinnedHash(StorageRecoveryTask $task): bool
    {
        return is_string($task->sha256)
            && $this->storagePathMatchesHash($task->disk, $task->path, $task->sha256);
    }

    /** Recovery mengadopsi target yang sudah direferensikan agar file sah tidak pernah dihapus. */
    private function markAdopted(StorageRecoveryTask $task, bool $countAttempt): void
    {
        $task->forceFill([
            'status' => StorageRecoveryTask::STATUS_ADOPTED,
            'attempts' => $task->attempts + ($countAttempt ? 1 : 0),
            'last_error' => null,
            'last_attempted_at' => now(),
            'completed_at' => now(),
        ])->save();
    }

    private function hasValidRetainedCounterpart(StorageRecoveryTask $task): bool
    {
        if ($task->category === self::CATEGORY_LEAVE_ATTACHMENT_PUBLIC_COPY) {
            return $this->storagePathMatchesHash(
                LeaveRequest::ATTACHMENT_STORAGE_DISK,
                $task->path,
                (string) $task->sha256,
            );
        }

        if ($task->category === self::CATEGORY_LEAVE_PROOF_LEGACY_SOURCE) {
            if ($task->disk !== 'local'
                || $task->source_disk !== 'local'
                || ! is_string($task->source_path)
                || $task->sha256 === null
                || ! $this->isLegacyLeaveProofPath($task->path, $task->owner_id)
                || ! $this->isCanonicalLeaveProofPath($task->source_path, $task->owner_id)) {
                return false;
            }

            $target = StorageRecoveryTask::query()
                ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                ->where('status', StorageRecoveryTask::STATUS_ADOPTED)
                ->where('category', self::CATEGORY_LEAVE_PROOF)
                ->where('disk', 'local')
                ->where('path', $task->source_path)
                ->where('owner_id', $task->owner_id)
                ->where('source_disk', 'local')
                ->where('source_path', $task->path)
                ->where('sha256', $task->sha256)
                ->first();

            return $target !== null
                && $this->storagePathMatchesHash('local', $target->path, $task->sha256);
        }

        if ($task->category !== self::CATEGORY_LEAVE_ATTACHMENT
            || $task->disk !== 'public'
            || $task->sha256 === null
            || ! $this->isSafeLegacyLeaveAttachmentPath($task->path)) {
            return true;
        }

        $found = false;
        $targets = StorageRecoveryTask::query()
            ->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
            ->where('status', StorageRecoveryTask::STATUS_ADOPTED)
            ->where('category', self::CATEGORY_LEAVE_ATTACHMENT)
            ->where('source_disk', 'public')
            ->where('source_path', $task->path)
            ->where('sha256', $task->sha256)
            ->orderBy('id')
            ->cursor();

        foreach ($targets as $target) {
            $found = true;
            try {
                $this->assertValidMigrationTarget($target);
            } catch (Throwable) {
                return false;
            }
            if (! $this->storagePathMatchesHash($target->disk, $target->path, $task->sha256)) {
                return false;
            }
        }

        return $found;
    }

    /** Source yang dipin harus tetap tersedia sebelum recovery menghapus target PREPARED. */
    private function hasValidPreparedMigrationSource(StorageRecoveryTask $task): bool
    {
        return $task->operation === StorageRecoveryTask::OPERATION_MIGRATION_TARGET
            && $task->status === StorageRecoveryTask::STATUS_PREPARED
            && is_string($task->source_disk)
            && is_string($task->source_path)
            && is_string($task->sha256)
            && $this->storagePathMatchesHash($task->source_disk, $task->source_path, $task->sha256);
    }

    private function storagePathMatchesHash(string $diskName, string $path, string $sha256): bool
    {
        try {
            $disk = Storage::disk($diskName);

            return $disk->exists($path)
                && hash_equals(
                    $sha256,
                    BoundedStorageHasher::sha256($disk, $path, self::LEAVE_ATTACHMENT_MAX_BYTES),
                );
        } catch (Throwable) {
            return false;
        }
    }

    private function assertValidOptionalSha256(?string $sha256, bool $required = false): void
    {
        if ($sha256 === null && ! $required) {
            return;
        }

        if (! is_string($sha256) || preg_match('/\A[0-9a-f]{64}\z/D', $sha256) !== 1) {
            throw new RuntimeException('SHA-256 task recovery tidak valid.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function persistWithCriticalSignal(array $attributes, string $path, ?string $sourcePath): StorageRecoveryTask
    {
        try {
            return $this->persistIdempotently($attributes);
        } catch (Throwable $exception) {
            try {
                Log::critical('Manifest cleanup storage sensitif gagal ditulis.', [
                    'category' => $attributes['category'] ?? 'unknown',
                    'disk' => $attributes['disk'] ?? 'unknown',
                    'error' => $this->sanitizeError($exception->getMessage(), $path, $sourcePath),
                ]);
            } catch (Throwable) {
                // Pelaporan sekunder tidak boleh mengganti exception persistence yang harus terlihat caller.
            }

            throw $exception;
        }
    }

    private function markManualReview(StorageRecoveryTask $task, string $detail): void
    {
        $task->forceFill([
            'status' => StorageRecoveryTask::STATUS_MANUAL_REVIEW,
            'attempts' => $task->attempts + 1,
            'last_error' => $this->sanitizeError($detail, $task->path, $task->source_path),
            'last_attempted_at' => now(),
            'completed_at' => null,
        ])->save();
    }

    /** @param list<string> $allowedExtensions */
    private function isExactUuidFilePath(string $path, string $prefix, string $ownerId, array $allowedExtensions): bool
    {
        $segments = explode('/', $path);
        $prefixSegments = explode('/', $prefix);
        $expectedCount = count($prefixSegments) + 2;
        if (count($segments) !== $expectedCount
            || array_slice($segments, 0, count($prefixSegments)) !== $prefixSegments
            || $segments[count($prefixSegments)] !== strtolower($ownerId)
            || ! $this->isNormalizedRelativePath($path)) {
            return false;
        }

        $filename = $segments[$expectedCount - 1];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $uuid = pathinfo($filename, PATHINFO_FILENAME);

        return basename($filename) === $filename
            && Str::isUuid($uuid)
            && in_array($extension, $allowedExtensions, true);
    }

    private function isNormalizedRelativePath(string $path): bool
    {
        return $path !== ''
            && $path === trim($path)
            && ! str_contains($path, '\\')
            && ! str_starts_with($path, '/')
            && ! str_contains($path, ':')
            && ! str_contains($path, "\0")
            && ! collect(explode('/', $path))->contains(
                fn (string $segment): bool => $segment === '' || $segment === '.' || $segment === '..',
            );
    }

    private function sanitizeError(string $detail, string $path, ?string $sourcePath): string
    {
        $safe = str_replace(array_filter([$path, $sourcePath]), '[path]', $detail);
        $safe = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $safe) ?? 'Kegagalan storage tidak diketahui.';

        return Str::limit($safe, 2000, '');
    }
}
