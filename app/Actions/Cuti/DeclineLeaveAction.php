<?php

namespace App\Actions\Cuti;

use App\Actions\Cuti\Concerns\BuildsLeaveDecisionAuditPayload;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Menutup pengajuan sebagai Tidak Disetujui tanpa memutasi saldo cuti. */
class DeclineLeaveAction
{
    use BuildsLeaveDecisionAuditPayload;

    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly NotificationService $notifications,
    ) {}

    public function execute(LeaveRequest $leaveRequest, Employee $actor, string $expectedActiveStepId, int $expectedRevisionVersion, string $komentar, Request $request): LeaveRequest
    {
        $statusSebelum = $leaveRequest->status;
        $stepSebelum = $leaveRequest->steps()
            ->where('status', 'active')
            ->where('approver_employee_id', $actor->id)
            ->first();

        // Keputusan dan jejaknya disatukan dalam satu transaksi supaya pengajuan tidak pernah
        // berpindah status tanpa baris audit yang menerangkan siapa yang memutuskan.
        $leaveRequest = DB::transaction(function () use ($leaveRequest, $actor, $expectedActiveStepId, $expectedRevisionVersion, $komentar, $request, $statusSebelum, $stepSebelum): LeaveRequest {
            $leaveRequest = $this->approvals->decline($leaveRequest, $actor, $expectedActiveStepId, $expectedRevisionVersion, $komentar);
            $auditPayload = $this->decisionAuditPayload($statusSebelum, $leaveRequest, $stepSebelum, $actor, 'NOT_APPROVED', $komentar);

            AuditService::logOrFail(
                'NOT_APPROVED',
                'LeaveRequest',
                $leaveRequest->id,
                $auditPayload['old'],
                $auditPayload['new'],
                $request,
            );

            return $leaveRequest;
        });

        $pemohon = $leaveRequest->employee;
        $approvalId = $leaveRequest->getRelation('lastRecordedApproval')?->id;

        if ($pemohon !== null) {
            $this->notifications->createForEmployee(
                $pemohon,
                'cuti.tidak_disetujui',
                'Pengajuan Cuti Tidak Disetujui',
                'Pengajuan cuti Anda tidak disetujui. Silakan periksa catatan keputusan.',
                // Pemohon diarahkan ke detail pengajuannya; path relatif internal agar link aman lintas host.
                [
                    'leave_request_id' => $leaveRequest->id,
                    'leave_approval_id' => $approvalId,
                    'url' => route('cuti.show', ['id' => $leaveRequest->id], false),
                ],
            );
        }

        return $leaveRequest;
    }
}
