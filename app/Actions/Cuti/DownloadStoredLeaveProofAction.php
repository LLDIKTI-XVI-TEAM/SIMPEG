<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\LeaveProofDocumentStorageService;
use App\Services\Cuti\LeaveRequestReadAccess;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadStoredLeaveProofAction
{
    public function __construct(
        private readonly LeaveProofDocumentStorageService $documents,
        private readonly LeaveRequestReadAccess $readAccess,
    ) {}

    /** Caller HTTP wajib memeriksa record sebelum streaming artefak, bukan hanya role pada URL. */
    public function forActor(LeaveRequest $leaveRequest, ?User $actor, bool $inline): StreamedResponse
    {
        abort_unless($this->readAccess->canRead($leaveRequest, $actor), 403);

        return $this->execute($leaveRequest, $inline);
    }

    /** Mengunduh PDF persetujuan asli, termasuk histori administratif, tanpa membuka path storage. */
    public function execute(
        LeaveRequest $leaveRequest,
        bool $inline,
        ?string $downloadFilename = null,
    ): StreamedResponse {
        $leaveRequest->loadMissing('proof');
        $proof = $leaveRequest->proof;

        abort_if(
            ! in_array($leaveRequest->status, ['disetujui', LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED], true)
            || $proof === null
            || ! $this->documents->isValidExistingDocument(
                $leaveRequest->id,
                $proof->document_path,
                $proof->document_mime,
            ),
            404,
        );

        // Route stored langsung mempertahankan nama ringkas; endpoint umum dapat meneruskan kontrak historisnya.
        $filename = $downloadFilename
            ?? 'Formulir_Cuti_'.strtoupper(substr($leaveRequest->id, 0, 8)).'.pdf';
        $headers = [
            'Content-Type' => LeaveProofDocumentStorageService::MIME,
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($inline) {
            $headers['Content-Disposition'] = 'inline; filename="'.$filename.'"';

            return Storage::disk(LeaveProofDocumentStorageService::DISK)
                ->response($proof->document_path, $filename, $headers);
        }

        return Storage::disk(LeaveProofDocumentStorageService::DISK)
            ->download($proof->document_path, $filename, $headers);
    }
}
