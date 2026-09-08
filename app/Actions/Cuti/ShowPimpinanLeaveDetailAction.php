<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\EmployeeFileStorageService;

final class ShowPimpinanLeaveDetailAction
{
    public function __construct(
        private readonly EmployeeFileStorageService $files,
        private readonly BuildAdministrativeLeavePostponementContextAction $administrativeContext,
    ) {}

    /**
     * Route monitoring menjaga gate baca; hak mutasi dan alasan administratif dievaluasi terpisah.
     * Saldo dibatasi tiga tahun yang memang ditampilkan agar histori tidak dimuat tak terbatas.
     *
     * @return array<string, mixed>
     */
    public function execute(LeaveRequest $leave, User $actor): array
    {
        $year = now()->year;
        $leave->load([
            'employee.leaveBalances' => fn ($query) => $query->whereBetween('tahun', [$year - 2, $year]),
            'jenisCuti',
            'steps.approver',
            'approvals.approver',
            'proof',
        ]);
        $activeStep = $leave->steps->firstWhere('status', 'active');

        return [
            ...$this->administrativeContext->execute($leave, $actor),
            'leave' => $leave,
            'activeStep' => $activeStep,
            'canDecide' => $activeStep !== null
                && $activeStep->approver_employee_id === $actor->employee_id
                && in_array($leave->status, ['menunggu_approval', 'ditangguhkan'], true),
            'balances' => $leave->employee?->leaveBalances->keyBy('tahun') ?? collect(),
            'attachmentAvailable' => $this->files->hasLeaveAttachment($leave->lampiran_path, $leave->employee_id),
        ];
    }
}
