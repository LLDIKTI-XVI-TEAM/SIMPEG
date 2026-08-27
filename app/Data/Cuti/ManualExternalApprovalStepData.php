<?php

namespace App\Data\Cuti;

use App\Models\LeaveUsageExternalApprovalStep;

final readonly class ManualExternalApprovalStepData
{
    public function __construct(
        public int $order,
        public string $stepType,
        public string $approverSource,
        public ?string $approverEmployeeId,
        public ?string $externalName,
        public ?string $externalPosition,
        public ?string $externalInstitution,
        public string $actedOn,
        public ?string $decisionNote,
    ) {}

    /** Hasil historis diturunkan server agar Admin tidak dapat mengarang keputusan tahap. */
    public function resultCode(): string
    {
        return match ($this->stepType) {
            LeaveUsageExternalApprovalStep::TYPE_VERIFIER => LeaveUsageExternalApprovalStep::RESULT_VERIFIED,
            LeaveUsageExternalApprovalStep::TYPE_KEPALA_BAGIAN => LeaveUsageExternalApprovalStep::RESULT_APPROVED,
            LeaveUsageExternalApprovalStep::TYPE_PYBMC => LeaveUsageExternalApprovalStep::RESULT_FINAL_APPROVED,
        };
    }
}
