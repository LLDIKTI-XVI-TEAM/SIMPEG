<?php

namespace App\Services\Cuti;

use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Services\StorageRecoveryService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class LeaveUsageDocumentService
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(private readonly StorageRecoveryService $recovery) {}

    /**
     * Menyimpan bukti pada private disk dengan nama UUID agar nama asli tidak menjadi path yang dapat ditebak.
     *
     * @return array{original_name:string,stored_name:string,path:string,disk:string,mime_type:string,size_bytes:int,recovery_task_id:string}
     */
    public function store(UploadedFile $document, string $employeeId): array
    {
        Validator::make(['dokumen' => $document], [
            'dokumen' => ['required', 'file', 'max:10240', 'mimetypes:'.implode(',', self::ALLOWED_MIME_TYPES)],
        ])->validate();

        $extension = strtolower($document->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['dokumen' => 'Ekstensi dokumen harus PDF, DOC, DOCX, JPG, JPEG, atau PNG.']);
        }

        $storedName = Str::uuid().'.'.$extension;
        $directory = LeaveUsageDocument::PATH_PREFIX.'/'.$employeeId;
        $expectedPath = $directory.'/'.$storedName;
        $realPath = $document->getRealPath();
        $sha256 = is_string($realPath) ? hash_file('sha256', $realPath) : false;
        if (! is_string($sha256)) {
            throw ValidationException::withMessages(['dokumen' => 'Dokumen bukti gagal dihitung sebelum disimpan.']);
        }
        $recoveryTask = $this->recovery->prepareLeaveUsageDocumentCreationTarget(
            $expectedPath,
            $employeeId,
            $sha256,
        );
        $path = Storage::disk(LeaveUsageDocument::STORAGE_DISK)->putFileAs($directory, $document, $storedName);

        if ($path === false || $path !== $expectedPath) {
            throw ValidationException::withMessages(['dokumen' => 'Dokumen bukti gagal disimpan.']);
        }

        return [
            'original_name' => $document->getClientOriginalName(),
            'stored_name' => $storedName,
            'path' => $path,
            'disk' => LeaveUsageDocument::STORAGE_DISK,
            'mime_type' => (string) $document->getMimeType(),
            'size_bytes' => (int) $document->getSize(),
            'recovery_task_id' => $recoveryTask->id,
        ];
    }

    /**
     * Mengikat metadata bukti hanya ke fakta manual sehingga dokumen tidak dapat dipindahkan ke sumber lain.
     *
     * @param  array{original_name:string,stored_name:string,path:string,disk:string,mime_type:string,size_bytes:int,recovery_task_id:string}  $stored
     */
    public function attachToUsage(LeaveUsageRecord $record, array $stored, User $actor): LeaveUsageDocument
    {
        if ($record->source_type !== LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL) {
            throw ValidationException::withMessages(['usage_record' => 'Dokumen manual hanya boleh terkait fakta manual eksternal.']);
        }

        return LeaveUsageDocument::query()->create(array_merge(Arr::except($stored, ['recovery_task_id']), [
            'leave_usage_record_id' => $record->id,
            'uploaded_by' => $actor->id,
        ]));
    }

    /** Adoption pasca-commit bersifat best effort; recovery tetap melindungi task PREPARED bila database gagal. */
    public function adoptNewDocument(string $taskId, string $employeeId, string $path): bool
    {
        try {
            $this->recovery->markLeaveUsageDocumentCreationTargetAdopted($taskId, $employeeId, $path);

            return true;
        } catch (Throwable $exception) {
            try {
                Log::critical('Intent target dokumen pemakaian cuti gagal diadopsi setelah commit.', [
                    'task_id' => $taskId,
                    'employee_id' => $employeeId,
                    'error_type' => $exception::class,
                ]);
            } catch (Throwable) {
                // Pelaporan sekunder tidak boleh mengubah fakta pemakaian yang sudah committed.
            }

            return false;
        }
    }

    /**
     * Mengompensasi kegagalan transaksi dengan menghapus hanya file baru, bukan dokumen historis.
     *
     * @param  array{path:string,disk:string}|null  $stored
     */
    public function deleteNewFile(?array $stored): bool
    {
        if ($stored === null) {
            return true;
        }

        $disk = $stored['disk'] ?? null;
        $path = $stored['path'] ?? null;
        if ($disk !== LeaveUsageDocument::STORAGE_DISK || ! is_string($path)) {
            return false;
        }

        $segments = explode('/', $path);
        $employeeId = $segments[2] ?? null;
        if (! is_string($employeeId) || ! $this->isCanonicalStoredPath($path, $employeeId, basename($path))) {
            return false;
        }

        $task = $this->recovery->scheduleDelete(
            StorageRecoveryService::CATEGORY_LEAVE_USAGE_DOCUMENT,
            LeaveUsageDocument::STORAGE_DISK,
            $path,
            $employeeId,
        );

        return $this->recovery->attempt($task->id);
    }

    /** Path harus tepat `cuti/pemakaian/{employee UUID}/{file UUID}.{ext}` tanpa subdirektori tambahan. */
    public function isCanonicalStoredPath(string $path, string $employeeId, string $storedName): bool
    {
        return basename($storedName) === $storedName
            && basename($path) === $storedName
            && $this->recovery->isCanonicalLeaveUsagePath($path, $employeeId);
    }

    /**
     * Mengunci unduhan pada artifact yang masih cocok dengan manifest ADOPTED,
     * sehingga metadata append-only tidak dapat menunjuk byte privat yang telah berubah.
     */
    public function isValidExistingDocument(string $employeeId, string $path, string $storedName): bool
    {
        if (! $this->isCanonicalStoredPath($path, $employeeId, $storedName)) {
            return false;
        }

        try {
            return $this->recovery->verifyAdoptedLeaveUsageDocumentArtifact($employeeId, $path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Membatasi metadata audit pada nama asli, MIME, dan ukuran agar path, disk, serta nama privat tidak bocor.
     *
     * @param  array{original_name:string,mime_type:string,size_bytes:int}  $stored
     */
    public function auditMetadata(array $stored): array
    {
        return [
            'original_name' => $stored['original_name'],
            'mime_type' => $stored['mime_type'],
            'size_bytes' => $stored['size_bytes'],
        ];
    }
}
