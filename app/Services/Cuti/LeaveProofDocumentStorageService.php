<?php

namespace App\Services\Cuti;

use App\Models\StorageRecoveryTask;
use App\Services\StorageRecoveryService;
use App\Support\Cuti\LeaveProofPathContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class LeaveProofDocumentStorageService
{
    public const DISK = 'local';

    public const MIME = 'application/pdf';

    public function __construct(private readonly StorageRecoveryService $recovery) {}

    /** Membentuk path privat yang terikat ke UUID pengajuan dan tidak dapat dipilih oleh caller. */
    public function newPath(string $leaveRequestId): string
    {
        if (! Str::isUuid($leaveRequestId)) {
            throw new RuntimeException('Identitas pengajuan bukti cuti tidak sah.');
        }

        return 'leave-proofs/'.strtolower($leaveRequestId).'/'.Str::uuid().'.pdf';
    }

    /** Menulis byte PDF hanya pada folder privat canonical milik pengajuan. */
    public function putPdf(string $leaveRequestId, string $path, string $contents): void
    {
        if (! $this->isCanonicalPath($leaveRequestId, $path)
            || ! Storage::disk(self::DISK)->put($path, $contents)) {
            throw new RuntimeException('Dokumen bukti final gagal disimpan ke storage privat.');
        }
    }

    /** Manifest committed lebih dahulu agar hard crash tidak meninggalkan PDF tanpa jejak recovery. */
    public function prepareNewDocument(string $leaveRequestId, string $path, string $contents): StorageRecoveryTask
    {
        return $this->recovery->prepareLeaveProofCreationTarget(
            $path,
            $leaveRequestId,
            hash('sha256', $contents),
        );
    }

    /**
     * Adoption pasca-commit bersifat best effort; task PREPARED tetap aman dipulihkan bila database sementara gagal.
     */
    public function adoptNewDocument(string $taskId, string $leaveRequestId, string $path): bool
    {
        try {
            $this->recovery->markLeaveProofCreationTargetAdopted($taskId, $leaveRequestId, $path);

            return true;
        } catch (Throwable $exception) {
            try {
                Log::critical('Intent target bukti cuti gagal diadopsi setelah commit.', [
                    'task_id' => $taskId,
                    'leave_request_id' => $leaveRequestId,
                    'error_type' => $exception::class,
                ]);
            } catch (Throwable) {
                // Pelaporan sekunder tidak boleh mengubah hasil approval yang sudah committed.
            }

            return false;
        }
    }

    /**
     * Memvalidasi bukti existing secara fail-closed terhadap path privat, MIME, dan hash manifest ADOPTED.
     */
    public function isValidExistingDocument(
        string $leaveRequestId,
        ?string $path,
        ?string $mime,
    ): bool {
        if (! is_string($path)
            || $mime !== self::MIME
            || ! $this->isCanonicalPath($leaveRequestId, $path)) {
            return false;
        }

        try {
            return $this->recovery->verifyAdoptedLeaveProofArtifact($leaveRequestId, $path);
        } catch (Throwable) {
            return false;
        }
    }

    /** Menolak metadata existing yang tidak lagi menunjuk PDF privat milik pengajuan yang sama. */
    public function assertValidExistingDocument(
        string $leaveRequestId,
        ?string $path,
        ?string $mime,
    ): string {
        if (! $this->isValidExistingDocument($leaveRequestId, $path, $mime)) {
            throw new RuntimeException('Dokumen bukti existing tidak berada pada storage privat yang sah.');
        }

        return $path;
    }

    /** Kompensasi dicatat durable sebelum delete agar error utama terjaga tanpa membuat orphan tak terlacak. */
    public function deleteNewDocument(string $leaveRequestId, ?string $path): bool
    {
        if (! is_string($path) || ! $this->isCanonicalPath($leaveRequestId, $path)) {
            return false;
        }

        $task = $this->recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_PROOF,
            self::DISK,
            $path,
            $leaveRequestId,
        );

        return $this->recovery->attempt($task->id);
    }

    private function isCanonicalPath(string $leaveRequestId, string $path): bool
    {
        return LeaveProofPathContract::isCanonical($leaveRequestId, $path);
    }
}
