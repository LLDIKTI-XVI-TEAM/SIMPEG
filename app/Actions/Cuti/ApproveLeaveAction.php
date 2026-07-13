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

        // Aktor manusia dipisahkan dari approver Employee: user menjadi jejak akun penerbit bukti final,
        // sedangkan otorisasi step tetap berbasis employee. Request::user() dapat mengembalikan
        // Authenticatable|null, jadi dipersempit lewat instanceof alih-alih cast tak aman.
        $requestUser = $request->user();
        $actingUser = $requestUser instanceof User ? $requestUser : null;

        $leaveRequest = $this->approvals->approve($leaveRequest, $actor, $komentar, $actingUser);
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
                    // Pemohon diarahkan ke detail pengajuannya; path relatif internal agar link aman lintas host.
                    ['leave_request_id' => $leaveRequest->id, 'url' => route('cuti.show', ['id' => $leaveRequest->id], false)],
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
            // Approver tahap berikutnya diarahkan ke antrean approval; path relatif internal agar link aman lintas host.
            ['leave_request_id' => $leaveRequest->id, 'url' => route('cuti.approval', [], false)],
        );
    }
}
