<?php

namespace App\Actions\Cuti;

use App\Data\Cuti\VerifierLeaveHistoryRow;
use App\Models\LeaveCancellationRequest;
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
     *     canGenerateFormulir: bool,
     *     canResubmit: bool,
     *     canRequestCancellation: bool,
     *     latestCancellation: LeaveCancellationRequest|null,
     *     isOwner: bool,
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
        $isOwner = $cuti->employee_id === $user->employee_id;
        $relations = [
            'employee',
            'jenisCuti',
            'proof',
            'approvals.approver',
            'steps.approver',
        ];

        // Alasan pembatalan tidak dimuat untuk approver; status hold cukup dibaca dari request utama.
        if ($isOwner) {
            // Saat hold, pilih permohonan aktif karena timestamp setara dan UUID acak bukan urutan kronologis.
            $relations['cancellationRequests'] = fn ($query) => $query
                ->when(
                    $cuti->status === LeaveRequest::STATUS_CANCELLATION_PENDING,
                    fn ($query) => $query->where('status', LeaveCancellationRequest::STATUS_PENDING),
                )
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1);
        }

        $cuti->load($relations);

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
        $canGenerateFormulir = $canDownloadFormulir
            && $cuti->proof?->document_path === null
            && $user->hasPermission('cuti.proof.generate');
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
        $latestCancellation = $isOwner ? $cuti->cancellationRequests->first() : null;
        $canRequestCancellation = $isOwner
            && $user->hasPermission('cuti.create')
            && in_array($cuti->status, ['menunggu_approval', 'ditangguhkan'], true)
            && $latestCancellation?->status !== 'pending';
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
            'canGenerateFormulir' => $canGenerateFormulir,
            'attachmentAvailable' => $this->attachmentDownloads->canReadAsGeneralActor($cuti, $user)
                && $this->files->hasLeaveAttachment($cuti->lampiran_path, $cuti->employee_id),
            'canResubmit' => $isOwner
                && $user->hasPermission('cuti.create')
                && ($isRolloverReturn
                || ($cuti->status === 'menunggu_approval' && $cuti->approvals->isEmpty())),
            'canRequestCancellation' => $canRequestCancellation,
            'latestCancellation' => $latestCancellation,
            'isOwner' => $isOwner,
            'isRolloverReturn' => $isRolloverReturn,
            'targetBalance' => $targetBalance,
            'verifierContext' => $verifierContext,
            'activeStep' => $stage === null ? null : $cuti->steps->firstWhere('step_order', $stage),
        ];
    }
}
