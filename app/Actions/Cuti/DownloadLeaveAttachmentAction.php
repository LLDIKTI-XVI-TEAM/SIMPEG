<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\LeaveRequestReadAccess;
use App\Services\EmployeeFileStorageService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadLeaveAttachmentAction
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly LeaveRequestReadAccess $readAccess,
    ) {}

    /** Unduhan pemohon/read-all selalu memeriksa kepemilikan di backend. */
    public function forOwnerOrReadAll(LeaveRequest $leave, User $actor): StreamedResponse
    {
        abort_unless($this->canReadAsGeneralActor($leave, $actor), 403);

        return $this->download($leave);
    }

    /** Satu aturan baca dipakai detail dan unduhan: owner, read-all, atau approver snapshot. */
    public function canReadAsGeneralActor(LeaveRequest $leave, User $actor): bool
    {
        return $this->readAccess->canRead($leave, $actor);
    }

    /** URL role lama tidak menjadi jalan pintas melewati otorisasi record kanonis. */
    public function forPimpinan(LeaveRequest $leave, User $actor): StreamedResponse
    {
        return $this->forOwnerOrReadAll($leave, $actor);
    }

    /** Assignment lintas unit tetap sah; monitoring bawahan tetap membutuhkan permission dan scope. */
    public function forKepalaBagian(LeaveRequest $leave, User $actor): StreamedResponse
    {
        return $this->forOwnerOrReadAll($leave, $actor);
    }

    /** Validasi path sebelum setiap akses disk mencegah path DB yang ditamper menjadi traversal. */
    private function download(LeaveRequest $leave): StreamedResponse
    {
        abort_unless($this->files->hasLeaveAttachment($leave->lampiran_path, $leave->employee_id), 404);

        $path = $leave->lampiran_path;
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(LeaveRequest::ATTACHMENT_STORAGE_DISK);

        $response = $disk->download($path, 'Lampiran_Cuti_'.strtoupper(substr($leave->id, 0, 8)).'.'.$extension, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }
}
