<?php

namespace App\Actions\Cuti;

use App\Data\Cuti\VerifierLeaveHistoryRow;
use App\Http\Requests\Cuti\KepalaBagianLeaveFilterRequest;
use App\Http\Requests\Cuti\PimpinanLeaveFilterRequest;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\RefHariLibur;
use App\Models\User;
use App\Services\Cuti\LeaveRequestReadAccess;
use App\Services\EmployeeFileStorageService;
use App\Services\LeaveApprovalService;
use App\Support\Cuti\CutiPeriodFilter;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

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
     * @param  array<string, mixed>  $returnFilters
     * @return array{
     *     backLink: array{url: string, label: string},
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
    public function execute(LeaveRequest $cuti, User $user, ?string $from = null, array $returnFilters = []): array
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
            'backLink' => $this->backLink($user, $from, $returnFilters),
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

    /**
     * Asal pendek memilih route lokal; filter tidak pernah menentukan tujuan atau memberi scope baru.
     *
     * @param  array<string, mixed>  $returnFilters
     * @return array{url: string, label: string}
     */
    private function backLink(User $actor, ?string $from, array $returnFilters): array
    {
        $targets = ['approval' => ['cuti.approval', 'Kembali ke Menunggu Tindakan Saya']];
        if ($actor->hasPermission('cuti.read_all')) {
            $targets += [
                'pimpinan' => ['pimpinan.cuti.index', 'Kembali ke Monitoring Cuti'],
                'bawahan' => ['kepala-bagian.cuti.index', 'Kembali ke Cuti Bawahan'],
                'monitoring' => ['cuti', 'Kembali ke Monitoring Cuti'],
            ];
        }

        $target = $targets[$from ?? ''] ?? null;
        // Asal palsu atau izin yang dicabut tidak membawa filter halaman lain ke daftar pribadi.
        $filters = $target !== null || $from === null ? $this->returnFilters($from, $returnFilters) : [];

        return $target === null
            ? ['url' => route('cuti', ['scope' => 'own', ...$filters]), 'label' => 'Kembali ke Pengajuan Cuti Saya']
            : ['url' => route($target[0], $filters), 'label' => $target[1]];
    }

    /**
     * Pakai kontrak filter halaman asal; input cacat dibuang tanpa menghalangi pembacaan detail.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function returnFilters(?string $from, array $filters): array
    {
        $paginationRules = [
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
        $rules = match ($from) {
            'approval' => [],
            'pimpinan' => (new PimpinanLeaveFilterRequest)->rules(),
            'bawahan' => (new KepalaBagianLeaveFilterRequest)->rules(),
            default => [
                'status' => ['nullable', 'string', Rule::in([
                    'pending', 'menunggu', 'disetujui', 'ditunda', 'ditangguhkan',
                    LeaveRequest::STATUS_ADMINISTRATIVELY_POSTPONED, 'ditangguhkan_tugas_dinas',
                    LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER, LeaveRequest::STATUS_CANCELLATION_PENDING,
                    LeaveRequest::STATUS_CANCELLED, 'perlu_perubahan', 'tidak_disetujui',
                ])],
                'jenis' => ['nullable', 'string', 'max:255'],
                'periode' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (CutiPeriodFilter::parse($value) === null) {
                        $fail('Periode tidak valid.');
                    }
                }],
                'tahun' => ['nullable', 'digits:4', 'integer', 'min:1'],
                ...($from === 'monitoring' ? [
                    'search' => ['nullable', 'string', 'max:100'],
                    'unit' => ['nullable', 'uuid'],
                ] : []),
            ],
        };
        $rules = [...$rules, ...$paginationRules];
        $filters = array_filter(Arr::only($filters, array_keys($rules)), fn (mixed $value): bool => $value === null || is_string($value) || is_int($value));

        return Validator::make($filters, $rules)->valid();
    }
}
