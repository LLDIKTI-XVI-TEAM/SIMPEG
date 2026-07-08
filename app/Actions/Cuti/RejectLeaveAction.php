<?php

namespace App\Actions\Cuti;

use App\Actions\Cuti\Concerns\BuildsLeaveDecisionAuditPayload;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/** Menolak pengajuan cuti secara terminal tanpa pemotongan saldo. */
class RejectLeaveAction
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

        $leaveRequest = $this->approvals->reject($leaveRequest, $actor, $komentar);
        $auditPayload = $this->decisionAuditPayload($statusSebelum, $leaveRequest, $stepSebelum, $actor, 'REJECT', $komentar);

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
                'cuti.tidak_disetujui',
                'Pengajuan Cuti Tidak Disetujui',
                'Pengajuan cuti Anda tidak disetujui. Silakan periksa catatan keputusan.',
                ['leave_request_id' => $leaveRequest->id],
            );
        }

        return $leaveRequest;
    }
}
