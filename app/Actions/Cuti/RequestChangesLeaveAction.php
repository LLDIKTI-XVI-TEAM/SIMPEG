<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/** Mengembalikan pengajuan cuti ke pemohon untuk diperbaiki dengan catatan wajib dari approver. */
class RequestChangesLeaveAction
{
    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly NotificationService $notifications,
    ) {}

    public function execute(LeaveRequest $leaveRequest, Employee $actor, string $komentar, Request $request): LeaveRequest
    {
        $statusSebelum = $leaveRequest->status;

        $leaveRequest = $this->approvals->requestChanges($leaveRequest, $actor, $komentar);

        AuditService::log(
            'UPDATE',
            'LeaveRequest',
            $leaveRequest->id,
            ['status' => $statusSebelum],
            ['status' => $leaveRequest->status, 'decision' => 'REQUEST_CHANGES', 'komentar' => $komentar],
            $request,
        );

        $pemohon = $leaveRequest->employee;

        if ($pemohon !== null) {
            $this->notifications->createForEmployee(
                $pemohon,
                'cuti.perlu_perubahan',
                'Pengajuan Cuti Perlu Perubahan',
                'Pengajuan cuti Anda perlu diperbaiki sebelum dapat diproses lanjut.',
                ['leave_request_id' => $leaveRequest->id],
            );
        }

        return $leaveRequest;
    }
}
