<?php

namespace App\Actions\Cuti\Concerns;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;

trait BuildsLeaveDecisionAuditPayload
{
    /**
     * Payload audit keputusan memuat identitas step agar riwayat approval tetap dapat ditelusuri.
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
                'status' => $statusSebelum,
                'step_order' => $stepOrder,
                'step_label' => $stepLabel,
                'approver_id' => $actor->id,
            ],
            'new' => [
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
