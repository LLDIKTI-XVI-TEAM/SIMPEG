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

        // Keputusan dan jejaknya disatukan dalam satu transaksi supaya pengajuan tidak pernah
        // berpindah status tanpa baris audit yang menerangkan siapa yang memutuskan.
        $leaveRequest = DB::transaction(function () use ($leaveRequest, $actor, $komentar, $request, $statusSebelum, $stepSebelum): LeaveRequest {
            $leaveRequest = $this->approvals->requestChanges($leaveRequest, $actor, $komentar);
            $auditPayload = $this->decisionAuditPayload($statusSebelum, $leaveRequest, $stepSebelum, $actor, 'CHANGE_REQUESTED', $komentar);

            AuditService::logOrFail(
                'CHANGE_REQUESTED',
                'LeaveRequest',
                $leaveRequest->id,
                $auditPayload['old'],
                $auditPayload['new'],
                $request,
            );

            return $leaveRequest;
        });

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
