<?php

namespace App\Actions\Cuti;

use App\Data\Cuti\VerifierLeaveHistoryRow;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefHariLibur;
use App\Models\User;
use App\Services\EmployeeFileStorageService;
use App\Services\LeaveApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Menyusun seluruh konteks detail cuti setelah akses baca pengguna ditegakkan. */
class BuildCutiDetailAction
{
    public function __construct(
        private readonly LeaveApprovalService $approvals,
        private readonly DownloadOfficialLeavePdfAction $pdfAction,
        private readonly PreviewLeaveBalanceAction $balancePreview,
        private readonly BuildVerifierLeaveContextAction $verifierContext,
        private readonly EmployeeFileStorageService $files,
        private readonly DownloadLeaveAttachmentAction $attachmentDownloads,
    ) {}

    /**
     * Akses baca memakai snapshot approver agar keputusan lama tetap dapat ditelusuri,
     * sedangkan izin bertindak tetap dibatasi pada tahap aktif dan status yang dapat diputus.
     *
     * @return array{
     *     cuti: LeaveRequest,
     *     canAct: bool,
     *     isVerifierContext: bool,
     *     canDownloadFormulir: bool,
     *     canResubmit: bool,
     *     isRolloverReturn: bool,
     *     targetBalance: array<string, mixed>|null,
     *     verifierContext: array{
     *         balance: array<string, mixed>,
     *         cutiBersama: Collection<int, RefHariLibur>,
     *         riwayatTahunan: Collection<int, VerifierLeaveHistoryRow>
     *     }|null,
     *     activeStep: LeaveRequestStep|null
     * }
     */
    public function execute(LeaveRequest $cuti, User $user): array
    {
        $cuti->load(['employee', 'jenisCuti', 'proof', 'approvals.approver', 'steps.approver']);

        $stage = $this->approvals->pendingStage($cuti);
        $employeeId = $user->employee_id;
        // Mapping pegawai wajib tersedia agar nilai null tidak cocok dengan snapshot approver kosong.
        $isCurrentApprover = $employeeId !== null
            && $stage !== null
            && $this->approvals->approverEmployeeIdForStage($cuti, $stage) === $employeeId;
        // Snapshot menyimpan seluruh pihak yang berwenang menelusuri pengajuan,
        // termasuk approver yang sudah selesai atau masih menunggu tahapnya.
        $isAnySnapshotApprover = $employeeId !== null
            && $cuti->steps->contains(
                fn (LeaveRequestStep $step): bool => $step->approver_employee_id === $employeeId,
            );
        $canAct = $isCurrentApprover
            && in_array($cuti->status, LeaveApprovalService::ACTIONABLE_STATUSES, true);
        $canDownloadFormulir = $this->pdfAction->canDownload($cuti, $user);
        $canReadAll = $user->hasPermission('cuti.read_all');
        $canReadOwn = $user->hasPermission('cuti.read_own')
            && $user->employee_id !== null
            && $cuti->employee_id === $user->employee_id;

        // Snapshot approver lama tetap boleh membaca pengajuan untuk kebutuhan audit,
        // tetapi tidak memperoleh izin bertindak setelah tahapnya selesai.
        abort_if(
            ! $canReadAll
            && ! $canReadOwn
            && ! $isAnySnapshotApprover
            && ! $canDownloadFormulir,
            403,
        );

        $isRolloverReturn = $cuti->status === LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER;
        $isVerifierContext = $canAct || $canReadAll;
        $targetBalance = $isRolloverReturn && $cuti->employee !== null && $cuti->rollover_target_year !== null
            ? $this->balancePreview->execute(
                $cuti->employee,
                Carbon::create($cuti->rollover_target_year, 1, 1)->startOfDay(),
            )
            : null;
        $verifierContext = $isVerifierContext && $cuti->employee !== null
            ? $this->verifierContext->execute($cuti->employee, $cuti->tanggal_mulai ?? now(), $cuti)
            : null;

        return [
            'cuti' => $cuti,
            'canAct' => $canAct,
            'isVerifierContext' => $isVerifierContext,
            'canDownloadFormulir' => $canDownloadFormulir,
            'attachmentAvailable' => $this->attachmentDownloads->canReadAsGeneralActor($cuti, $user)
                && $this->files->hasLeaveAttachment($cuti->lampiran_path, $cuti->employee_id),
            'canResubmit' => in_array($cuti->status, ['perlu_perubahan', LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER], true)
                && $cuti->employee_id === $user->employee_id,
            'isRolloverReturn' => $isRolloverReturn,
            'targetBalance' => $targetBalance,
            'verifierContext' => $verifierContext,
            'activeStep' => $stage === null ? null : $cuti->steps->firstWhere('step_order', $stage),
        ];
    }
}
