<?php

namespace App\Actions\Cuti\Concerns;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;

trait BuildsLeaveDecisionAuditPayload
{
    /**
     * Payload audit keputusan memuat identitas step agar riwayat approval tetap dapat ditelusuri.
     * leave_request_id dan employee_id disertakan di sisi old dan new agar setiap baris audit
     * dapat ditelusuri langsung ke pengajuan dan pegawai pemohon tanpa join tambahan.
     * Kolom status pada old/new merepresentasikan keadaan sebelum dan sesudah keputusan diambil.
     *
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    private function decisionAuditPayload(
        string $statusSebelum,
        LeaveRequest $leaveRequest,
        ?LeaveRequestStep $step,
        Employee $actor,
        string $decision,
        ?string $komentar = null,
    ): array {
        $approval = $leaveRequest->approvals()
            ->where('approver_id', $actor->id)
            ->latest('acted_at')
            ->first();

        $stepOrder = $step?->step_order;
        $stepLabel = $step?->role_label;

        return [
            'old' => [
                'leave_request_id' => $leaveRequest->id,
                'employee_id' => $leaveRequest->employee_id,
                'status' => $statusSebelum,
                'step_order' => $stepOrder,
                'step_label' => $stepLabel,
                'approver_id' => $actor->id,
            ],
            'new' => [
                'leave_request_id' => $leaveRequest->id,
                'employee_id' => $leaveRequest->employee_id,
                'status' => $leaveRequest->status,
                'decision' => $decision,
                'step_order' => $stepOrder,
                'step_label' => $stepLabel,
                'approver_id' => $actor->id,
                'acted_at' => $approval?->acted_at?->toJSON(),
                'komentar' => $komentar,
            ],
        ];
    }
}
