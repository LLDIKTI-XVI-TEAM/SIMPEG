<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * Mengoordinasikan tindakan menunda pengajuan cuti.
 * Transisi status ke Ditunda berada di LeaveApprovalService; Action ini menangani audit dan notifikasi.
 * Penundaan bersifat reversible, sehingga pemohon diberi tahu agar dapat menindaklanjuti.
 */
class PostponeLeaveAction
{
    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Menunda pengajuan cuti atas nama approver yang bertindak; alasan penundaan wajib diberikan.
     */
    public function execute(LeaveRequest $leaveRequest, Employee $actor, string $komentar, Request $request): LeaveRequest
    {
        $statusSebelum = $leaveRequest->status;

        $leaveRequest = $this->approvals->postpone($leaveRequest, $actor, $komentar);

        // Audit dan notifikasi dijalankan setelah transaksi penundaan berhasil agar kegagalan keduanya
        // tidak membatalkan penundaan yang sudah sah tersimpan.
        AuditService::log(
            'POSTPONE',
            'LeaveRequest',
            $leaveRequest->id,
            ['status' => $statusSebelum],
            ['status' => $leaveRequest->status, 'komentar' => $komentar],
            $request,
        );

        $pemohon = $leaveRequest->employee;

        if ($pemohon !== null) {
            $this->notifications->createForEmployee(
                $pemohon,
                'cuti.ditunda',
                'Pengajuan Cuti Ditunda',
                'Pengajuan cuti Anda ditunda oleh approver. Silakan periksa catatan penundaan.',
                ['leave_request_id' => $leaveRequest->id],
            );
        }

        return $leaveRequest;
    }
}
