<?php

namespace App\Actions\Cuti;

use App\Actions\Cuti\Concerns\BuildsLeaveDecisionAuditPayload;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * Mengoordinasikan tindakan menyetujui pengajuan cuti.
 * Logika transisi snapshot, skip duplikat, dan pemotongan saldo berada di LeaveApprovalService.
 * Action ini menangani orkestrasi tepian: pencatatan audit dan notifikasi pihak terkait setelah transisi.
 */
class ApproveLeaveAction
{
    use BuildsLeaveDecisionAuditPayload;

    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly NotificationService $notifications,
        private readonly GenerateLeaveProofAction $proofs,
    ) {}

    /**
     * Menyetujui pengajuan cuti atas nama approver yang bertindak.
     */
    public function execute(LeaveRequest $leaveRequest, Employee $actor, ?string $komentar, Request $request): LeaveRequest
    {
        $statusSebelum = $leaveRequest->status;
        $stepSebelum = $leaveRequest->steps()
            ->where('status', 'active')
            ->where('approver_employee_id', $actor->id)
            ->first();

        $leaveRequest = $this->approvals->approve($leaveRequest, $actor, $komentar);
        $auditPayload = $this->decisionAuditPayload($statusSebelum, $leaveRequest, $stepSebelum, $actor, 'APPROVE', $komentar);

        // Audit dan notifikasi bersifat fire-and-forget setelah transaksi persetujuan berhasil di service,
        // agar kegagalan audit/notifikasi tidak membatalkan persetujuan yang sudah sah tersimpan.
        AuditService::log(
            'APPROVE',
            'LeaveRequest',
            $leaveRequest->id,
            $auditPayload['old'],
            $auditPayload['new'],
            $request,
        );

        $this->notifyAfterApproval($leaveRequest);

        if ($leaveRequest->status === 'disetujui') {
            $this->proofs->execute($leaveRequest, $request->user() instanceof User ? $request->user() : null);
        }

        return $leaveRequest;
    }

    /**
     * Memberi tahu pihak yang relevan setelah persetujuan.
     * Bila masih ada tahap menunggu, approver tahap berikutnya diberi tahu; bila sudah final,
     * pemohon diberi tahu bahwa cutinya disetujui.
     */
    private function notifyAfterApproval(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->status === 'disetujui') {
            $pemohon = $leaveRequest->employee;

            if ($pemohon !== null) {
                $this->notifications->createForEmployee(
                    $pemohon,
                    'cuti.disetujui',
                    'Pengajuan Cuti Disetujui',
                    'Pengajuan cuti Anda telah disetujui sepenuhnya.',
                    ['leave_request_id' => $leaveRequest->id],
                );
            }

            return;
        }

        $stage = $this->approvals->pendingStage($leaveRequest);

        if ($stage === null) {
            return;
        }

        $approverId = $this->approvals->approverEmployeeIdForStage($leaveRequest, $stage);

        if ($approverId === null) {
            return;
        }

        $approver = Employee::find($approverId);

        if ($approver === null) {
            return;
        }

        $this->notifications->createForEmployee(
            $approver,
            'cuti.menunggu_persetujuan',
            'Pengajuan Cuti Menunggu Persetujuan',
            'Terdapat pengajuan cuti yang menunggu persetujuan Anda.',
            ['leave_request_id' => $leaveRequest->id],
        );
    }
}
