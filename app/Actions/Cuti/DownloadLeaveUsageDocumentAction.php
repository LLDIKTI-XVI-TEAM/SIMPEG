<?php

namespace App\Actions\Cuti;

use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageRecord;
use App\Models\User;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use App\Services\Cuti\LeaveUsageDocumentService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadLeaveUsageDocumentAction
{
    public function __construct(
        private readonly LeaveUsageAuthorizationService $authorization,
        private readonly LeaveUsageDocumentService $documents,
    ) {}

    /** Menyiapkan unduhan privat setelah otorisasi dan seluruh metadata kepemilikan diverifikasi fail-closed. */
    public function execute(string $usageId, string $documentId, User $actor): StreamedResponse
    {
        // Guard wajib mendahului lookup agar keberadaan fakta atau dokumen tidak bocor ke role lain.
        $this->authorization->assertCanManageManual($actor);
        abort_unless(Str::isUuid($usageId) && Str::isUuid($documentId), 404);

        $record = LeaveUsageRecord::query()
            ->whereKey($usageId)
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->firstOrFail();
        $document = LeaveUsageDocument::query()
            ->whereKey($documentId)
            ->where('leave_usage_record_id', $record->id)
            ->firstOrFail();

        abort_unless($document->disk === LeaveUsageDocument::STORAGE_DISK, 404);

        $path = str_replace('\\', '/', trim($document->path));
        $validPath = $path === $document->path
            && $this->documents->isCanonicalStoredPath(
                $path,
                (string) $record->employee_id,
                (string) $document->stored_name,
            );

        abort_unless(
            $validPath && $this->documents->isValidExistingDocument(
                (string) $record->employee_id,
                $path,
                (string) $document->stored_name,
            ),
            404,
        );

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(LeaveUsageDocument::STORAGE_DISK);

        $response = $disk->download($path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
