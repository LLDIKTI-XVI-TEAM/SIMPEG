<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\EmployeeStatusHistory;
use App\Models\LeaveRequest;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use App\Models\StorageRecoveryTask;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmployeeFileStorageService
{
    public function __construct(
        private readonly StorageRecoveryService $recovery,
        private readonly TransactionSideEffectManager $sideEffects,
    ) {}

    public function storePhoto(UploadedFile $file): string
    {
        return $this->store($file, 'employees/photos');
    }

    public function storeSk(UploadedFile $file): string
    {
        return $this->storeEmployeeDocument($file, 'sk');
    }

    /**
     * Menyimpan lampiran pendukung pengajuan cuti (mis. surat keterangan).
     * Disimpan terpisah pada folder cuti agar berkas cuti tidak tercampur dengan dokumen pegawai lain.
     */
    /** @return array{path:string,recovery_task_id:string} */
    public function storeLampiran(UploadedFile $file, string $employeeId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getMimeType();
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];

        if (! in_array($extension, $allowedExtensions, true)
            || ! in_array($mimeType, $allowedMimeTypes, true)) {
            throw new \InvalidArgumentException('Format lampiran cuti tidak diizinkan.');
        }

        $storedName = Str::uuid().'.'.$extension;
        $directory = LeaveRequest::ATTACHMENT_PATH_PREFIX.'/'.$employeeId;
        $expectedPath = $directory.'/'.$storedName;
        $realPath = $file->getRealPath();
        $sha256 = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        if (! is_string($sha256)) {
            throw new \RuntimeException('Lampiran pengajuan gagal dihitung sebelum disimpan.');
        }
        $recoveryTask = $this->recovery->prepareLeaveAttachmentCreationTarget(
            $expectedPath,
            $employeeId,
            $sha256,
        );
        $path = $file->storeAs($directory, $storedName, ['disk' => LeaveRequest::ATTACHMENT_STORAGE_DISK]);

        if ($path === false || $path !== $expectedPath) {
            throw new \RuntimeException('Gagal menyimpan lampiran pengajuan cuti.');
        }

        // Rollback request simulasi dijalankan setelah transaksi database berakhir, sehingga
        // manifest recovery dapat dipersistenkan dan menghapus hanya lampiran milik pegawai ini.
        $this->sideEffects->afterRollback(function () use ($path, $employeeId): void {
            $this->deleteLeaveAttachment($path, $employeeId);
        });

        return ['path' => $path, 'recovery_task_id' => $recoveryTask->id];
    }

    /**
     * Adoption ditunda sampai transaksi request terluar commit; tanpa scope luar,
     * caller sudah menyelesaikan transaksi domain sehingga adoption dapat langsung dicoba.
     */
    public function adoptLeaveAttachment(string $taskId, string $employeeId, string $path): bool
    {
        if ($this->sideEffects->afterCommit(
            fn () => $this->attemptLeaveAttachmentAdoption($taskId, $employeeId, $path),
        )) {
            return true;
        }

        return $this->attemptLeaveAttachmentAdoption($taskId, $employeeId, $path);
    }

    /** Mencatat lalu mencoba cleanup berkas cuti privat baru setelah transaksi domain gagal. */
    public function deleteLeaveAttachment(?string $path, string $employeeId): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        if (! $this->isCanonicalLeaveAttachmentPath($path, $employeeId)) {
            Log::warning('Menolak menghapus lampiran cuti dengan path tidak kanonis.');

            return false;
        }

        $task = $this->recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
            LeaveRequest::ATTACHMENT_STORAGE_DISK,
            $path,
            $employeeId,
        );

        return $this->recovery->attempt($task->id);
    }

    /**
     * Menentukan disk dari path row terkunci dan menulis task di transaksi yang sama dengan penggantian referensi.
     * Path di luar canonical-local atau exact legacy-public ditolak sebelum mutasi database dilakukan.
     */
    public function scheduleReplacedLeaveAttachment(?string $path, string $employeeId): ?StorageRecoveryTask
    {
        if ($path === null || $path === '') {
            return null;
        }

        if ($this->isCanonicalLeaveAttachmentPath($path, $employeeId)) {
            return $this->recovery->scheduleDelete(
                StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
                LeaveRequest::ATTACHMENT_STORAGE_DISK,
                $path,
                $employeeId,
            );
        }

        if ($this->recovery->isSafeLegacyLeaveAttachmentPath($path)) {
            // Legacy source dapat dipakai lintas pegawai, sehingga owner task sengaja null dan referensi dicek global.
            return $this->recovery->scheduleDelete(
                StorageRecoveryService::CATEGORY_LEAVE_ATTACHMENT,
                'public',
                $path,
            );
        }

        throw new \RuntimeException('Lampiran lama tidak memenuhi kontrak path storage yang aman.');
    }

    public function attemptRecoveryTask(?string $taskId): bool
    {
        if ($taskId === null) {
            return true;
        }

        // Penghapusan file lama tidak dapat di-rollback. Saat mutation berada di bawah
        // transaksi middleware, tunggu commit terluar agar referensi lama tidak hidup kembali.
        if ($this->sideEffects->afterCommit(fn () => $this->recovery->attempt($taskId))) {
            return true;
        }

        return $this->recovery->attempt($taskId);
    }

    /** Unduhan hanya tersedia bila path kanonis dan byte cocok dengan manifest final yang diadopsi. */
    public function hasLeaveAttachment(?string $path, string $employeeId): bool
    {
        return is_string($path)
            && $this->recovery->verifyAdoptedLeaveAttachmentArtifact($employeeId, $path);
    }

    /**
     * Menyimpan berkas lainnya tanpa URL publik; akses file wajib melalui route berotorisasi.
     */
    public function storeBerkasLainnya(UploadedFile $file, string $employeeId): string
    {
        return $this->storeEmployeeDocument($file, "berkas/{$employeeId}");
    }

    /**
     * Menyimpan dokumen pegawai ke disk khusus privat agar tidak dapat dilewati melalui symlink publik.
     */
    public function storeEmployeeDocument(UploadedFile $file, string $directory): string
    {
        return $this->storeOnDisk($file, $directory, Document::STORAGE_DISK);
    }

    /**
     * Menyimpan SK status dengan manifest durable sebelum byte ditulis agar crash
     * sebelum commit metadata dapat dipulihkan tanpa menghapus file milik pegawai lain.
     *
     * @return array{path:string,recovery_task_id:string}
     */
    public function storeEmployeeStatusDocument(UploadedFile $file, string $employeeId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png'], true)) {
            throw new \InvalidArgumentException('Format SK status pegawai tidak diizinkan.');
        }

        $storedName = Str::uuid().'.'.$extension;
        $directory = $employeeId.'/sk_status_pegawai';
        $expectedPath = $directory.'/'.$storedName;
        $realPath = $file->getRealPath();
        $sha256 = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        if (! is_string($sha256)) {
            throw new \RuntimeException('SK status pegawai gagal dihitung sebelum disimpan.');
        }

        $recoveryTask = $this->recovery->prepareEmployeeStatusDocumentCreationTarget(
            $expectedPath,
            $employeeId,
            $sha256,
        );
        $path = Storage::disk(Document::STORAGE_DISK)->putFileAs($directory, $file, $storedName);

        if ($path === false || $path !== $expectedPath) {
            throw new \RuntimeException('Gagal menyimpan SK status pegawai.');
        }

        // Bila middleware membatalkan transaksi request setelah Action selesai, cleanup
        // tetap dicatat durable; manifest PREPARED menjadi pagar untuk crash sebelum callback.
        $this->sideEffects->afterRollback(
            fn () => $this->deleteEmployeeStatusDocument($path, $employeeId),
        );

        return ['path' => $path, 'recovery_task_id' => $recoveryTask->id];
    }

    /** Adoption ditunda oleh middleware transaksi; recovery tetap dapat mengadopsi manifest bila proses crash. */
    public function adoptEmployeeStatusDocument(string $taskId, string $employeeId, string $path): bool
    {
        if ($this->sideEffects->afterCommit(
            fn () => $this->attemptEmployeeStatusDocumentAdoption($taskId, $employeeId, $path),
        )) {
            return true;
        }

        return $this->attemptEmployeeStatusDocumentAdoption($taskId, $employeeId, $path);
    }

    /** Cleanup gagal-transaksi dicatat durable dan owner-scoped sebelum penghapusan dicoba. */
    public function deleteEmployeeStatusDocument(?string $path, string $employeeId): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        if (! $this->recovery->isCanonicalEmployeeStatusDocumentPath($path, $employeeId)) {
            Log::warning('Menolak menghapus SK status pegawai dengan path tidak kanonis.');

            return false;
        }

        $task = $this->recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_EMPLOYEE_STATUS_DOCUMENT,
            Document::STORAGE_DISK,
            $path,
            $employeeId,
        );

        return $this->recovery->attempt($task->id);
    }

    public function deletePublicFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Gagal menghapus file publik pegawai.', ['path' => $path]);
            }
        } catch (\Throwable $exception) {
            // Kegagalan kompensasi storage tidak boleh menutupi exception transaksi yang menjadi akar masalah.
            Log::warning('Gagal menghapus file publik pegawai.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function deleteEmployeeDocumentFile(?string $path): void
    {
        $this->deleteOnDisk($path, Document::STORAGE_DISK, 'dokumen privat pegawai');
    }

    /**
     * Menghapus file SK yang telah digantikan hanya setelah referensi baru committed.
     * Pemeriksaan lintas tabel melindungi file legacy yang kebetulan masih dibagi oleh lebih dari satu record.
     */
    public function deleteReplacedEmployeeDocumentFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $cleanup = function () use ($path): void {
            if ($this->isEmployeeDocumentFileStillReferenced($path)) {
                return;
            }

            $this->deleteEmployeeDocumentFile($path);
        };

        if (! $this->sideEffects->afterCommit($cleanup)) {
            $cleanup();
        }
    }

    private function isEmployeeDocumentFileStillReferenced(string $path): bool
    {
        return Document::query()->where('file_path', $path)->exists()
            || EmployeeStatusHistory::query()->where('file_sk', $path)->exists()
            || RankHistory::query()->where('file_sk', $path)->exists()
            || PositionHistory::query()->where('file_sk', $path)->exists()
            || SalaryHistory::query()->where('file_sk', $path)->exists()
            || DisciplineRecord::query()->where('file_sk', $path)->exists()
            || Appointment::query()->where('file_sk', $path)->exists();
    }

    private function store(UploadedFile $file, string $directory): string
    {
        return $this->storeOnDisk($file, $directory, 'public');
    }

    private function deleteOnDisk(?string $path, string $disk, string $label): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            if (! Storage::disk($disk)->delete($path)) {
                Log::warning("Gagal menghapus {$label}.", ['path' => $path]);
            }
        } catch (\Throwable $exception) {
            Log::warning("Gagal menghapus {$label}.", ['path' => $path, 'error' => $exception->getMessage()]);
        }
    }

    /** Path lampiran harus persis milik employee dan nama UUID hasil server sebelum baca atau hapus. */
    private function isCanonicalLeaveAttachmentPath(?string $path, string $employeeId): bool
    {
        return is_string($path)
            && $this->recovery->isCanonicalLeaveAttachmentPath($path, $employeeId);
    }

    /** Kegagalan adoption pasca-commit tidak boleh membuat klien mengulang pengajuan yang sudah sah. */
    private function attemptLeaveAttachmentAdoption(string $taskId, string $employeeId, string $path): bool
    {
        try {
            $this->recovery->markLeaveAttachmentCreationTargetAdopted($taskId, $employeeId, $path);

            return true;
        } catch (\Throwable $exception) {
            try {
                Log::critical('Intent target lampiran pengajuan gagal diadopsi setelah commit.', [
                    'task_id' => $taskId,
                    'employee_id' => $employeeId,
                    'error_type' => $exception::class,
                ]);
            } catch (\Throwable) {
                // Pelaporan sekunder tidak boleh mengubah pengajuan yang sudah committed.
            }

            return false;
        }
    }

    /** Kegagalan adoption dicatat tanpa path privat; worker recovery dapat menyelesaikannya idempoten. */
    private function attemptEmployeeStatusDocumentAdoption(string $taskId, string $employeeId, string $path): bool
    {
        try {
            $this->recovery->markEmployeeStatusDocumentCreationTargetAdopted($taskId, $employeeId, $path);

            return true;
        } catch (\Throwable $exception) {
            try {
                Log::critical('Intent target SK status pegawai gagal diadopsi setelah commit.', [
                    'task_id' => $taskId,
                    'employee_id' => $employeeId,
                    'error_type' => $exception::class,
                ]);
            } catch (\Throwable) {
                // Pelaporan sekunder tidak boleh mengubah jadwal status yang sudah committed.
            }

            return false;
        }
    }

    private function storeOnDisk(
        UploadedFile $file,
        string $directory,
        string $disk,
        bool $registerRollbackCleanup = true,
    ): string {
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $filename = Str::uuid().'.'.$extension;
        $path = $file->storeAs($directory, $filename, ['disk' => $disk]);

        if ($path === false) {
            throw new \RuntimeException('Gagal menyimpan file upload pegawai.');
        }

        if ($registerRollbackCleanup) {
            // Upload umum mengikuti disk aktual; lampiran cuti mendaftarkan recovery owner-scoped sendiri.
            $this->sideEffects->afterRollback(
                fn () => $this->deleteOnDisk($path, $disk, "berkas pada disk {$disk}"),
            );
        }

        return $path;
    }
}
