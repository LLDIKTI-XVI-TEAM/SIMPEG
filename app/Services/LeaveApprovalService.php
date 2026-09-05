<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\Cuti\LeaveEligibilityService;
use App\Services\Cuti\LeaveProofService;
use App\Services\Cuti\LeaveUsageRecordService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mesin persetujuan cuti berbasis snapshot step per pengajuan.
 * Snapshot menjadi sumber otorisasi agar perubahan konfigurasi approval tidak mengubah pengajuan berjalan.
 */
class LeaveApprovalService
{
    private const STATUS_MENUNGGU = 'menunggu_approval';

    private const STATUS_DISETUJUI = 'disetujui';

    private const STATUS_DITANGGUHKAN = 'ditangguhkan';

    private const STATUS_TIDAK_DISETUJUI = 'tidak_disetujui';

    /**
     * Status yang masih boleh diputus approver.
     *
     * Dipakai bersama oleh gate service, indikator tombol keputusan, dan counter antrean supaya
     * ketiganya tidak berbeda. Keberadaan step aktif saja tidak cukup: rollover mempertahankan step
     * aktif sebagai snapshot pada pengajuan yang sudah dikembalikan ke pemohon.
     *
     * @var list<string>
     */
    public const ACTIONABLE_STATUSES = [self::STATUS_MENUNGGU, self::STATUS_DITANGGUHKAN];

    /**
     * LeaveProofService di-inject agar penerbitan bukti final ikut dalam transaksi persetujuan.
     * Injeksi konstruktor dipilih ketimbang service locator agar dependensi eksplisit dan mudah diuji.
     */
    public function __construct(
        private readonly LeaveProofService $proofs,
        private readonly LeaveBalanceService $balances,
        private readonly LeaveBalanceReservationService $reservations,
        private readonly LeaveEligibilityService $eligibility,
        private readonly LeaveUsageRecordService $usageRecords,
    ) {}

    /**
     * Menyetujui step aktif. Step berikutnya diaktifkan, dan final approval
     * membentuk fakta pemakaian yang menjadi satu-satunya sumber replay saldo tahunan.
     *
     * Aktor Employee adalah approver snapshot, sedangkan User adalah akun manusia yang wajib cocok agar
     * ledger, audit, dan bukti final tidak pernah memakai identitas implisit atau aktor sistem.
     */
    public function approve(
        LeaveRequest $leaveRequest,
        Employee $actor,
        string $expectedActiveStepId,
        int $expectedRevisionVersion,
        ?string $komentar = null,
        ?User $actingUser = null,
        ?Request $httpRequest = null,
    ): LeaveRequest {
        // Seluruh persetujuan membutuhkan akun manusia eksplisit sebelum lock dan mutasi apa pun.
        if ($actingUser === null || $actingUser->employee_id !== $actor->id) {
            throw new AuthorizationException('Akun Anda tidak cocok dengan approver yang berwenang untuk tahap persetujuan ini.');
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $expectedActiveStepId, $expectedRevisionVersion, $komentar, $actingUser, $httpRequest): LeaveRequest {
            // Mutasi request existing selalu mengunci request lebih dahulu, lalu employee,
            // agar approval, penangguhan dinas, rollover, dan resubmit tidak membentuk siklus lock.
            $locked = LeaveRequest::query()
                ->with('jenisCuti')
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            $employee = Employee::query()
                ->whereKey($locked->employee_id)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertApprovalActionable($locked);
            $activeStep = $this->activeStepOrFail($locked);
            $this->assertActorMatchesStep($activeStep, $actor);
            $this->assertExpectedActiveStep($activeStep, $expectedActiveStepId);
            $this->assertExpectedRevisionVersion($locked, $expectedRevisionVersion);

            $activeStep->forceFill([
                'status' => 'approved',
                'decision_note' => $komentar,
                'acted_at' => Carbon::now(),
            ])->save();

            $recordedApproval = $this->recordApproval($locked, $actor, $activeStep->step_order, 'APPROVE', $komentar);

            $nextStep = $this->activateNextStep($locked, $activeStep->step_order);

            if ($nextStep !== null) {
                $locked->forceFill(['status' => self::STATUS_MENUNGGU])->save();
                $locked->setRelation('lastRecordedApproval', $recordedApproval);

                return $locked;
            }

            // Cabang final mengonversi reservasi, menutup request, lalu menulis fakta historis.
            // Replay saldo berasal dari fakta itu; tidak ada lagi debit langsung pada ringkasan saldo.
            $isAnnual = $locked->jenisCuti?->reducesAnnualBalance() ?? false;
            $isCutiBesar = $locked->jenisCuti?->code === 'besar';

            if ($isAnnual) {
                // Rule 5 diperiksa ulang saat final karena Cuti Besar dapat menjadi final
                // setelah pengajuan tahunan membuat reservasi tetapi sebelum disetujui.
                $this->balances->assertAnnualLeaveAllowed($employee, $locked->tanggal_mulai->year);
            }

            if ($isCutiBesar) {
                // Kelayakan persisted dan konflik saldo dicek setelah mutex pegawai agar final approval
                // tidak dapat berlomba dengan submit atau resubmit Cuti Tahunan pada tahun yang sama.
                $this->eligibility->assertCutiBesarCanBeFinallyApproved($locked, $employee);
                $this->balances->assertCutiBesarCanBeFinallyApproved(
                    $employee,
                    $locked->tanggal_mulai->year,
                );
            }

            $this->reservations->convertForFinalApproval($locked, $actingUser, $httpRequest);
            $locked->forceFill(['status' => self::STATUS_DISETUJUI])->save();
            $locked = $locked->refresh()->load('jenisCuti');
            $this->usageRecords->recordApprovedRequest($locked, $actingUser, $httpRequest);
            $this->proofs->generateForApprovedRequest($locked, $actor, $actingUser, $httpRequest);

            return $locked->refresh()->setRelation('lastRecordedApproval', $recordedApproval);
        });
    }

    /**
     * Menangguhkan pengajuan pada step aktif tanpa mengubah approver aktif.
     * Approver yang sama dapat melanjutkan dengan approve pada step yang sama.
     */
    public function postpone(LeaveRequest $leaveRequest, Employee $actor, string $expectedActiveStepId, int $expectedRevisionVersion, string $komentar): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $actor, $expectedActiveStepId, $expectedRevisionVersion, $komentar): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $this->assertApprovalActionable($locked);
            $activeStep = $this->activeStepOrFail($locked);
            $this->assertActorMatchesStep($activeStep, $actor);
            $this->assertExpectedActiveStep($activeStep, $expectedActiveStepId);
            $this->assertExpectedRevisionVersion($locked, $expectedRevisionVersion);

            $activeStep->forceFill(['decision_note' => $komentar])->save();
            $recordedApproval = $this->recordApproval($locked, $actor, $activeStep->step_order, 'POSTPONE', $komentar);

            $locked->forceFill(['status' => self::STATUS_DITANGGUHKAN])->save();
            $locked->setRelation('lastRecordedApproval', $recordedApproval);

            return $locked;
        });
    }

    /**
     * Menutup pengajuan sebagai Tidak Disetujui tanpa memotong saldo.
     * Step aktif dan seluruh step lanjutan ditutup agar pengajuan tidak kembali muncul di antrean.
     */
    public function decline(LeaveRequest $leaveRequest, Employee $actor, string $expectedActiveStepId, int $expectedRevisionVersion, string $komentar): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $actor, $expectedActiveStepId, $expectedRevisionVersion, $komentar): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $this->assertApprovalActionable($locked);
            $activeStep = $this->activeStepOrFail($locked);
            $this->assertActorMatchesStep($activeStep, $actor);
            $this->assertExpectedActiveStep($activeStep, $expectedActiveStepId);
            $this->assertExpectedRevisionVersion($locked, $expectedRevisionVersion);

            $activeStep->forceFill([
                'status' => self::STATUS_TIDAK_DISETUJUI,
                'decision_note' => $komentar,
                'acted_at' => Carbon::now(),
            ])->save();

            $recordedApproval = $this->recordApproval($locked, $actor, $activeStep->step_order, 'NOT_APPROVED', $komentar);
            $this->reservations->releaseForNotApproved($locked);
            $locked->steps()
                ->where('status', 'pending')
                ->update([
                    'status' => 'skipped',
                    'skipped_reason' => 'request_not_approved',
                    'decision_note' => 'Dilewati karena pengajuan sudah tidak disetujui.',
                    'acted_at' => Carbon::now(),
                ]);
            $locked->forceFill(['status' => self::STATUS_TIDAK_DISETUJUI])->save();
            $locked->setRelation('lastRecordedApproval', $recordedApproval);

            return $locked;
        });
    }

    /** Menentukan step approval aktif yang sedang menunggu tindakan approver. */
    public function pendingStage(LeaveRequest $leaveRequest): ?int
    {
        return $leaveRequest->steps()
            ->where('status', 'active')
            ->orderBy('step_order')
            ->value('step_order');
    }

    /** Mengembalikan approver snapshot untuk step tertentu. */
    public function approverEmployeeIdForStage(LeaveRequest $leaveRequest, int $stage): ?string
    {
        return $leaveRequest->steps()
            ->where('step_order', $stage)
            ->value('approver_employee_id');
    }

    private function assertApprovalActionable(LeaveRequest $leaveRequest): void
    {
        if (! in_array($leaveRequest->status, self::ACTIONABLE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan cuti ini belum dapat diproses oleh approver.',
            ]);
        }
    }

    private function activeStepOrFail(LeaveRequest $leaveRequest): LeaveRequestStep
    {
        $step = $leaveRequest->steps()
            ->where('status', 'active')
            ->orderBy('step_order')
            ->lockForUpdate()
            ->first();

        if ($step === null) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan cuti ini belum memiliki step approval aktif.',
            ]);
        }

        return $step;
    }

    private function assertActorMatchesStep(LeaveRequestStep $step, Employee $actor): void
    {
        if ($step->approver_employee_id !== $actor->id) {
            throw new AuthorizationException('Anda bukan approver yang berwenang untuk tahap persetujuan ini.');
        }
    }

    /** Token form hanya sah untuk step aktif yang sudah terkunci dan telah lolos otorisasi aktor. */
    private function assertExpectedActiveStep(LeaveRequestStep $step, string $expectedActiveStepId): void
    {
        if (strtolower($step->id) !== strtolower($expectedActiveStepId)) {
            throw ValidationException::withMessages([
                'active_step_id' => 'Tahap persetujuan telah berubah. Muat ulang halaman sebelum mengirim keputusan.',
            ]);
        }
    }

    /** Counter monotonic membedakan keputusan dari formulir lama meski timestamp sama. */
    private function assertExpectedRevisionVersion(LeaveRequest $leaveRequest, int $expectedRevisionVersion): void
    {
        if ($leaveRequest->revision_version !== $expectedRevisionVersion) {
            throw ValidationException::withMessages([
                'revision_version' => 'Pengajuan cuti telah diperbarui. Muat ulang halaman sebelum mengirim keputusan.',
            ]);
        }
    }

    private function recordApproval(LeaveRequest $leaveRequest, Employee $actor, int $stage, string $action, ?string $komentar): LeaveApproval
    {
        return LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $actor->id,
            'stage' => $stage,
            'action' => $action,
            'komentar' => $komentar,
            'acted_at' => Carbon::now(),
        ]);
    }

    private function activateNextStep(LeaveRequest $leaveRequest, int $currentOrder): ?LeaveRequestStep
    {
        $steps = $leaveRequest->steps()
            ->where('step_order', '>', $currentOrder)
            ->where('status', 'pending')
            ->orderBy('step_order')
            ->lockForUpdate()
            ->get();

        foreach ($steps as $step) {
            $step->forceFill(['status' => 'active'])->save();

            return $step;
        }

        return null;
    }
}
