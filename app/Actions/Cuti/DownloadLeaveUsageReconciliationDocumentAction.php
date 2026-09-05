<?php

namespace App\Actions\Cuti;

use App\Models\LeaveUsageDocument;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\User;
use App\Services\Cuti\LeaveUsageAuthorizationService;
use App\Services\Cuti\LeaveUsageDocumentService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadLeaveUsageReconciliationDocumentAction
{
    public function __construct(
        private readonly LeaveUsageAuthorizationService $authorization,
        private readonly LeaveUsageDocumentService $documents,
    ) {}

    /** Unduhan bukti koreksi dibatasi pemegang permission rekonsiliasi sebelum lookup metadata atau file. */
    public function execute(string $reconciliationId, string $documentId, User $actor): StreamedResponse
    {
        $this->authorization->assertCanReconcile($actor);
        abort_unless(Str::isUuid($reconciliationId) && Str::isUuid($documentId), 404);

        $set = LeaveUsageReconciliationSet::query()
            ->whereKey($reconciliationId)
            ->whereIn('status', [LeaveUsageReconciliationSet::STATUS_ACTIVE, LeaveUsageReconciliationSet::STATUS_SUPERSEDED])
            ->firstOrFail();
        $document = LeaveUsageDocument::query()
            ->whereKey($documentId)
            ->where('leave_usage_reconciliation_set_id', $set->id)
            ->whereNull('leave_usage_record_id')
            ->firstOrFail();

        abort_unless($document->disk === LeaveUsageDocument::STORAGE_DISK, 404);
        $path = str_replace('\\', '/', trim($document->path));
        $valid = $path === $document->path
            && $this->documents->isCanonicalStoredPath(
                $path,
                (string) $set->employee_id,
                (string) $document->stored_name,
            );
        $valid = $valid && pathinfo($document->stored_name, PATHINFO_FILENAME) === $document->id;
        abort_unless(
            $valid && $this->documents->isValidExistingDocument(
                (string) $set->employee_id,
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
