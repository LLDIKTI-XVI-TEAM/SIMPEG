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

/**
 * Mengoordinasikan tindakan menunda pengajuan cuti.
 * Transisi status ke ditangguhkan berada di LeaveApprovalService; Action ini menangani audit dan notifikasi.
 * Penundaan bersifat reversible, sehingga pemohon diberi tahu agar dapat menindaklanjuti.
 */
class PostponeLeaveAction
{
    use BuildsLeaveDecisionAuditPayload;

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
        $stepSebelum = $leaveRequest->steps()
            ->where('status', 'active')
            ->where('approver_employee_id', $actor->id)
            ->first();

        // Keputusan dan jejaknya disatukan dalam satu transaksi supaya pengajuan tidak pernah
        // berpindah status tanpa baris audit yang menerangkan siapa yang memutuskan. Notifikasi tetap
        // di luar transaksi agar kegagalan pengiriman tidak membatalkan penangguhan yang sah.
        $leaveRequest = DB::transaction(function () use ($leaveRequest, $actor, $komentar, $request, $statusSebelum, $stepSebelum): LeaveRequest {
            $leaveRequest = $this->approvals->postpone($leaveRequest, $actor, $komentar);
            $auditPayload = $this->decisionAuditPayload($statusSebelum, $leaveRequest, $stepSebelum, $actor, 'DEFER', $komentar);

            AuditService::logOrFail(
                'DEFER',
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
                'cuti.ditunda',
                'Pengajuan Cuti Ditangguhkan',
                'Pengajuan cuti Anda ditangguhkan oleh approver. Silakan periksa catatan penangguhan.',
                // Pemohon diarahkan ke detail pengajuannya; path relatif internal agar link aman lintas host.
                ['leave_request_id' => $leaveRequest->id, 'url' => route('cuti.show', ['id' => $leaveRequest->id], false)],
            );
        }

        return $leaveRequest;
    }
}
