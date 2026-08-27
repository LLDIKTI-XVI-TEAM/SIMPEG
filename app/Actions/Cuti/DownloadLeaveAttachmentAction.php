<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\EmployeeFileStorageService;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadLeaveAttachmentAction
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly KepalaBagianScopeService $kepalaBagianScope,
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
        if ($actor->hasPermission('cuti.read_all') || $actor->employee_id === $leave->employee_id) {
            return true;
        }

        return $actor->employee_id !== null
            && $leave->steps()->where('approver_employee_id', $actor->employee_id)->exists();
    }

    /** Pimpinan dibatasi role backend meski endpoint telah dipagari route. */
    public function forPimpinan(LeaveRequest $leave, User $actor): StreamedResponse
    {
        abort_unless($actor->getEffectiveRole() === 'pimpinan', 403);

        return $this->download($leave);
    }

    /** Kepala Bagian hanya dapat membaca lampiran laporan langsungnya. */
    public function forKepalaBagian(LeaveRequest $leave, User $actor): StreamedResponse
    {
        abort_if($actor->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');
        abort_unless($this->kepalaBagianScope->hasDirectReport($actor, $leave->employee_id), 403);

        return $this->download($leave);
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
