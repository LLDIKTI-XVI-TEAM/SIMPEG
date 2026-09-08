<?php

namespace App\Actions\Cuti;

use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\User;
use App\Services\EmployeeFileStorageService;
use App\Services\Employees\KepalaBagianScopeService;

/** Menyusun detail cuti setelah memastikan pengajuan berasal dari bawahan langsung Kepala Bagian. */
class ShowKepalaBagianLeaveDetailAction
{
    public function __construct(
        private readonly KepalaBagianScopeService $scope,
        private readonly EmployeeFileStorageService $files,
        private readonly BuildAdministrativeLeavePostponementContextAction $administrativeContext,
    ) {}

    /**
     * @return array{
     *     leave: LeaveRequest,
     *     activeStep: LeaveRequestStep|null,
     *     canDecide: bool,
     *     attachmentAvailable: bool
     * }
     */
    public function execute(?User $actor, LeaveRequest $leave): array
    {
        if (! $actor instanceof User || $actor->employee_id === null) {
            abort(403, 'Akun Atasan Langsung belum tertaut ke data pegawai.');
        }

        abort_unless($this->scope->hasDirectReport($actor, $leave->employee_id), 403);

        $leave->load([
            'employee:id,nama_lengkap,nip,jabatan_terakhir,golongan_terakhir',
            'jenisCuti:id,nama,code',
            'steps.approver:id,nama_lengkap',
            'approvals.approver:id,nama_lengkap',
        ]);
        $activeStep = $leave->steps->firstWhere('status', 'active');

        return [
            ...$this->administrativeContext->execute($leave, $actor),
            'leave' => $leave,
            'activeStep' => $activeStep,
            'canDecide' => $activeStep !== null
                && $activeStep->approver_employee_id === $actor->employee_id
                && in_array($leave->status, ['menunggu_approval', 'ditangguhkan'], true),
            'attachmentAvailable' => $this->files->hasLeaveAttachment(
                $leave->lampiran_path,
                $leave->employee_id,
            ),
        ];
    }
}
