<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\LeaveApprovalService;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/** Menolak pengajuan cuti secara terminal tanpa pemotongan saldo. */
class RejectLeaveAction
{
    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly NotificationService $notifications,
    ) {}

    public function execute(LeaveRequest $leaveRequest, Employee $actor, string $komentar, Request $request): LeaveRequest
    {
        $statusSebelum = $leaveRequest->status;

        $leaveRequest = $this->approvals->reject($leaveRequest, $actor, $komentar);

        AuditService::log(
            'UPDATE',
            'LeaveRequest',
            $leaveRequest->id,
            ['status' => $statusSebelum],
            ['status' => $leaveRequest->status, 'decision' => 'REJECT', 'komentar' => $komentar],
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
