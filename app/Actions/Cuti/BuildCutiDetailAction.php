<?php

namespace App\Actions\Cuti;

use App\Data\Cuti\VerifierLeaveHistoryRow;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefHariLibur;
use App\Models\User;
use App\Services\Cuti\LeaveRequestReadAccess;
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
        private readonly BuildAdministrativeLeavePostponementContextAction $administrativeContext,
        private readonly LeaveRequestReadAccess $readAccess,
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
    public function execute(LeaveRequest $cuti, User $user, ?string $from = null): array
    {
        abort_unless($this->readAccess->canReadDetail($cuti, $user), 403);

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
        $canAct = $isCurrentApprover
            && in_array($cuti->status, LeaveApprovalService::ACTIONABLE_STATUSES, true);
        $canDownloadFormulir = $this->pdfAction->canDownload($cuti, $user);
        $canReadBalance = $this->readAccess->canReadBalance($cuti, $user);

        $isRolloverReturn = $cuti->status === LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER;
        $latestCancellation = $isOwner ? $cuti->cancellationRequests->first() : null;
        $canRequestCancellation = $isOwner
            && in_array($cuti->status, ['menunggu_approval', 'ditangguhkan'], true)
            && $latestCancellation?->status !== 'pending';
        // Assignment aktif membawa konteks keputusan; monitoring saja tidak memberikan saldo lintas pegawai.
        $isVerifierContext = $canAct || $canReadBalance;
        $targetBalance = ($isOwner || $isVerifierContext)
            && $isRolloverReturn && $cuti->employee !== null && $cuti->rollover_target_year !== null
            ? $this->balancePreview->execute(
                $cuti->employee,
                Carbon::create($cuti->rollover_target_year, 1, 1)->startOfDay(),
            )
            : null;
        $verifierContext = $isVerifierContext && $cuti->employee !== null
            ? $this->verifierContext->execute($cuti->employee, $cuti->tanggal_mulai ?? now(), $cuti)
            : null;

        return [
            ...$this->administrativeContext->execute($cuti, $user),
            'backLink' => $this->backLink($user, $from),
            'cuti' => $cuti,
            'canAct' => $canAct,
            'isVerifierContext' => $isVerifierContext,
            'canDownloadFormulir' => $canDownloadFormulir,
            'attachmentAvailable' => $this->attachmentDownloads->canReadAsGeneralActor($cuti, $user)
                && $this->files->hasLeaveAttachment($cuti->lampiran_path, $cuti->employee_id),
            'canResubmit' => $isOwner && ($isRolloverReturn
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

    /** Asal pendek dipetakan ke route lokal; query pengguna tidak boleh menjadi URL kembali bebas. */
    private function backLink(User $actor, ?string $from): array
    {
        if ($from === 'approval') {
            return ['url' => route('cuti.approval'), 'label' => 'Kembali ke Menunggu Tindakan Saya'];
        }

        if ($actor->hasPermission('cuti.read_all')) {
            return match ($from) {
                'pimpinan' => ['url' => route('pimpinan.cuti.index'), 'label' => 'Kembali ke Monitoring Cuti'],
                'bawahan' => ['url' => route('kepala-bagian.cuti.index'), 'label' => 'Kembali ke Cuti Bawahan'],
                'monitoring' => ['url' => route('cuti'), 'label' => 'Kembali ke Monitoring Cuti'],
                default => ['url' => route('cuti', ['scope' => 'own']), 'label' => 'Kembali ke Pengajuan Cuti Saya'],
            };
        }

        return ['url' => route('cuti', ['scope' => 'own']), 'label' => 'Kembali ke Pengajuan Cuti Saya'];
    }
}
