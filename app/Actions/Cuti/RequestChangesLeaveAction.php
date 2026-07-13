<?php

namespace App\Actions\Cuti;

use App\Actions\Cuti\Concerns\BuildsLeaveDecisionAuditPayload;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/** Mengembalikan pengajuan cuti ke pemohon untuk diperbaiki dengan catatan wajib dari approver. */
class RequestChangesLeaveAction
{
    use BuildsLeaveDecisionAuditPayload;

    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly NotificationService $notifications,
    ) {}

    public function execute(LeaveRequest $leaveRequest, Employee $actor, string $komentar, Request $request): LeaveRequest
    {
        $statusSebelum = $leaveRequest->status;
        $stepSebelum = $leaveRequest->steps()
            ->where('status', 'active')
            ->where('approver_employee_id', $actor->id)
            ->first();

        $leaveRequest = $this->approvals->requestChanges($leaveRequest, $actor, $komentar);
        $auditPayload = $this->decisionAuditPayload($statusSebelum, $leaveRequest, $stepSebelum, $actor, 'REQUEST_CHANGES', $komentar);

        AuditService::log(
            'UPDATE',
            'LeaveRequest',
            $leaveRequest->id,
            $auditPayload['old'],
            $auditPayload['new'],
            $request,
        );

        $pemohon = $leaveRequest->employee;

        if ($pemohon !== null) {
            $this->notifications->createForEmployee(
                $pemohon,
                'cuti.perlu_perubahan',
                'Pengajuan Cuti Perlu Perubahan',
                'Pengajuan cuti Anda perlu diperbaiki sebelum dapat diproses lanjut.',
                // Pemohon diarahkan ke detail pengajuannya untuk perbaikan; path relatif internal agar link aman lintas host.
                ['leave_request_id' => $leaveRequest->id, 'url' => route('cuti.show', ['id' => $leaveRequest->id], false)],
            );
        }

        return $leaveRequest;
    }
}
