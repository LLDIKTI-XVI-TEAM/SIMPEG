<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Services\Cuti\LeaveBalanceService;
use Illuminate\Auth\Access\AuthorizationException;
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

    private const STATUS_PERLU_PERUBAHAN = 'perlu_perubahan';

    private const STATUS_TIDAK_DISETUJUI = 'tidak_disetujui';

    /**
     * Menyetujui step aktif. Step berikutnya diaktifkan, duplikasi approver dilewati, dan final approval memotong saldo.
     */
    public function approve(LeaveRequest $leaveRequest, Employee $actor, ?string $komentar = null): LeaveRequest
    {
        $this->assertApprovalActionable($leaveRequest);
        $this->assertActorIsApprover($leaveRequest, $actor, $this->pendingStageOrFail($leaveRequest));

        return DB::transaction(function () use ($leaveRequest, $actor, $komentar): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $this->assertApprovalActionable($locked);
            $activeStep = $this->activeStepOrFail($locked);
            $this->assertActorMatchesStep($activeStep, $actor);

            $activeStep->forceFill([
                'status' => 'approved',
                'decision_note' => $komentar,
                'acted_at' => Carbon::now(),
            ])->save();

            $this->recordApproval($locked, $actor, $activeStep->step_order, 'APPROVE', $komentar);

            $nextStep = $this->activateNextStep($locked, $actor, $activeStep->step_order);

            if ($nextStep !== null) {
                $locked->forceFill(['status' => self::STATUS_MENUNGGU])->save();

                return $locked;
            }

            $this->deductBalanceIfRequired($locked);
            $locked->forceFill(['status' => self::STATUS_DISETUJUI])->save();

            return $locked;
        });
    }

    /**
     * Menangguhkan pengajuan pada step aktif tanpa mengubah approver aktif.
     * Approver yang sama dapat melanjutkan dengan approve pada step yang sama.
     */
    public function postpone(LeaveRequest $leaveRequest, Employee $actor, string $komentar): LeaveRequest
    {
        $this->assertApprovalActionable($leaveRequest);
        $this->assertActorIsApprover($leaveRequest, $actor, $this->pendingStageOrFail($leaveRequest));

        return DB::transaction(function () use ($leaveRequest, $actor, $komentar): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $this->assertApprovalActionable($locked);
            $activeStep = $this->activeStepOrFail($locked);
            $this->assertActorMatchesStep($activeStep, $actor);

            $activeStep->forceFill(['decision_note' => $komentar])->save();
            $this->recordApproval($locked, $actor, $activeStep->step_order, 'POSTPONE', $komentar);

            $locked->forceFill(['status' => self::STATUS_DITANGGUHKAN])->save();

            return $locked;
        });
    }

    /**
     * Mengembalikan pengajuan ke pemohon untuk diperbaiki tanpa memindahkan step aktif.
     * Catatan wajib menjadi dasar pemohon memperbaiki data sebelum mengirim ulang.
     */
    public function requestChanges(LeaveRequest $leaveRequest, Employee $actor, string $komentar): LeaveRequest
    {
        $this->assertApprovalActionable($leaveRequest);
        $this->assertActorIsApprover($leaveRequest, $actor, $this->pendingStageOrFail($leaveRequest));

        return DB::transaction(function () use ($leaveRequest, $actor, $komentar): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $this->assertApprovalActionable($locked);
            $activeStep = $this->activeStepOrFail($locked);
            $this->assertActorMatchesStep($activeStep, $actor);

            $activeStep->forceFill(['decision_note' => $komentar])->save();
            $this->recordApproval($locked, $actor, $activeStep->step_order, 'REQUEST_CHANGES', $komentar);

            $locked->forceFill(['status' => self::STATUS_PERLU_PERUBAHAN])->save();

            return $locked;
        });
    }

    /**
     * Menolak pengajuan secara terminal. Step aktif ditutup agar pengajuan tidak kembali muncul di antrean.
     */
    public function reject(LeaveRequest $leaveRequest, Employee $actor, string $komentar): LeaveRequest
    {
        $this->assertApprovalActionable($leaveRequest);
        $this->assertActorIsApprover($leaveRequest, $actor, $this->pendingStageOrFail($leaveRequest));

        return DB::transaction(function () use ($leaveRequest, $actor, $komentar): LeaveRequest {
            $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();
            $this->assertApprovalActionable($locked);
            $activeStep = $this->activeStepOrFail($locked);
            $this->assertActorMatchesStep($activeStep, $actor);

            $activeStep->forceFill([
                'status' => 'rejected',
                'decision_note' => $komentar,
                'acted_at' => Carbon::now(),
            ])->save();

            $this->recordApproval($locked, $actor, $activeStep->step_order, 'REJECT', $komentar);
            $locked->steps()
                ->where('status', 'pending')
                ->update([
                    'status' => 'skipped',
                    'skipped_reason' => 'request_rejected',
                    'decision_note' => 'Dilewati karena pengajuan sudah tidak disetujui.',
                    'acted_at' => Carbon::now(),
                ]);
            $locked->forceFill(['status' => self::STATUS_TIDAK_DISETUJUI])->save();

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

    private function pendingStageOrFail(LeaveRequest $leaveRequest): int
    {
        $stage = $this->pendingStage($leaveRequest);

        if ($stage === null) {
            throw ValidationException::withMessages([
                'status' => 'Pengajuan cuti ini belum memiliki step approval aktif.',
            ]);
        }

        return $stage;
    }

    private function assertApprovalActionable(LeaveRequest $leaveRequest): void
    {
        if (! in_array($leaveRequest->status, [self::STATUS_MENUNGGU, self::STATUS_DITANGGUHKAN], true)) {
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

    /** Memastikan aktor adalah approver pada snapshot step aktif, bukan sekadar pemegang role/permission. */
    private function assertActorIsApprover(LeaveRequest $leaveRequest, Employee $actor, int $stage): void
    {
        $approverId = $this->approverEmployeeIdForStage($leaveRequest, $stage);

        if ($approverId === null) {
            throw ValidationException::withMessages([
                'status' => 'Step approval aktif belum memiliki approver.',
            ]);
        }

        if ($approverId !== $actor->id) {
            throw new AuthorizationException('Anda bukan approver yang berwenang untuk tahap persetujuan ini.');
        }
    }

    private function assertActorMatchesStep(LeaveRequestStep $step, Employee $actor): void
    {
        if ($step->approver_employee_id !== $actor->id) {
            throw new AuthorizationException('Anda bukan approver yang berwenang untuk tahap persetujuan ini.');
        }
    }

    private function recordApproval(LeaveRequest $leaveRequest, Employee $actor, int $stage, string $action, ?string $komentar): void
    {
        LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $actor->id,
            'stage' => $stage,
            'action' => $action,
            'komentar' => $komentar,
            'acted_at' => Carbon::now(),
        ]);
    }

    private function activateNextStep(LeaveRequest $leaveRequest, Employee $actor, int $currentOrder): ?LeaveRequestStep
    {
        $steps = $leaveRequest->steps()
            ->where('step_order', '>', $currentOrder)
            ->where('status', 'pending')
            ->orderBy('step_order')
            ->lockForUpdate()
            ->get();

        foreach ($steps as $step) {
            if ($step->approver_employee_id === $actor->id) {
                $step->forceFill([
                    'status' => 'skipped',
                    'skipped_reason' => 'duplicate_approver',
                    'decision_note' => 'Dilewati otomatis karena approver sama dengan step sebelumnya.',
                    'acted_at' => Carbon::now(),
                ])->save();
                $this->recordApproval($leaveRequest, $actor, $step->step_order, 'SKIP', 'Dilewati otomatis karena approver sama dengan step sebelumnya.');

                continue;
            }

            $step->forceFill(['status' => 'active'])->save();

            return $step;
        }

        return null;
    }

    /**
     * Memotong saldo lewat service ledger agar final approval tidak memakai path summary lama.
     * Eligibility jenis cuti memakai metadata, bukan nama tampilan, agar aman dari perubahan label.
     */
    private function deductBalanceIfRequired(LeaveRequest $leaveRequest): void
    {
        app(LeaveBalanceService::class)->deductForFinalApproval($leaveRequest);
    }
}
