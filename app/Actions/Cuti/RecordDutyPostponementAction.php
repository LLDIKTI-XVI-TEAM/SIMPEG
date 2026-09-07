<?php

namespace App\Actions\Cuti;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApproval;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordDutyPostponementAction
{
    private const ACTIONABLE_STATUSES = ['menunggu_approval', 'ditangguhkan'];

    private const NOTIFICATION_TYPE = 'cuti.ditangguhkan_tugas_dinas';

    public function __construct(
        private readonly LeaveBalanceService $balances,
        private readonly LeaveBalanceReservationService $reservations,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Menutup workflow cuti tahunan sambil melindungi hak dan melepas reservasi secara atomik.
     */
    public function execute(
        LeaveRequest $leaveRequest,
        Employee $actor,
        User $actingUser,
        string $expectedActiveStepId,
        int $expectedRevisionVersion,
        string $reason,
    ): LeaveRequest {
        // Identitas akun dan approver snapshot harus sama sebelum transaksi agar akun lain tidak dapat meminjam otoritas pegawai.
        if ($actingUser->employee_id !== $actor->id) {
            throw new AuthorizationException('Akun Anda tidak cocok dengan approver yang berwenang untuk penangguhan tugas dinas.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Alasan penangguhan tugas dinas wajib diisi.',
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $actingUser, $expectedActiveStepId, $expectedRevisionVersion, $reason): LeaveRequest {
            $locked = LeaveRequest::query()
                ->whereKey($leaveRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load(['jenisCuti', 'employee']);

            if ($locked->status === LeaveRequest::STATUS_DUTY_POSTPONED) {
                $this->assertExistingTerminalContract($locked, $actor, $actingUser, $expectedActiveStepId, $expectedRevisionVersion, $reason);

                return $locked->refresh();
            }

            if (! in_array($locked->status, self::ACTIONABLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Pengajuan cuti ini tidak dapat ditangguhkan karena tugas dinas.',
                ]);
            }

            $activeStep = $locked->steps()
                ->where('status', 'active')
                ->orderBy('step_order')
                ->lockForUpdate()
                ->first();

            if ($activeStep === null) {
                throw ValidationException::withMessages([
                    'status' => 'Pengajuan cuti ini belum memiliki step approval aktif.',
                ]);
            }

            if ($activeStep->approver_employee_id !== $actor->id) {
                throw new AuthorizationException('Anda bukan approver snapshot yang berwenang untuk penangguhan tugas dinas ini.');
            }

            $this->assertExpectedActiveStep($activeStep, $expectedActiveStepId);
            $this->assertExpectedRevisionVersion($locked, $expectedRevisionVersion);

            $statusBefore = $locked->status;
            $actedAt = Carbon::now();
            $ledger = $this->balances->recordDutyPostponement($locked, $actingUser, $reason);
            $release = $this->reservations->releaseForDutyPostponement($locked, $actingUser);

            if ($release === null) {
                throw ValidationException::withMessages([
                    'saldo' => 'Event pelepasan reservasi penangguhan dinas tidak tercatat.',
                ]);
            }

            $activeStep->forceFill([
                'status' => LeaveRequestStep::STATUS_DUTY_POSTPONED,
                'decision_note' => $reason,
                'acted_at' => $actedAt,
            ])->save();
            $locked->steps()
                ->where('step_order', '>', $activeStep->step_order)
                ->where('status', 'pending')
                ->update([
                    'status' => 'skipped',
                    'skipped_reason' => LeaveRequestStep::SKIPPED_DUTY_POSTPONEMENT_TERMINAL,
                    'acted_at' => $actedAt,
                ]);
            $approval = LeaveApproval::create([
                'leave_request_id' => $locked->id,
                'approver_id' => $actor->id,
                'stage' => $activeStep->step_order,
                'action' => LeaveApproval::ACTION_DUTY_POSTPONEMENT,
                'komentar' => $reason,
                'acted_at' => $actedAt,
            ]);
            $locked->forceFill(['status' => LeaveRequest::STATUS_DUTY_POSTPONED])->save();

            $sourceYear = $locked->tanggal_mulai->year;
            $protectedDays = (int) $locked->jumlah_hari_kerja;
            $notification = $this->notifications->createForEmployee(
                $locked->employee,
                self::NOTIFICATION_TYPE,
                'Cuti Tahunan Ditangguhkan karena Tugas Dinas',
                'Pengajuan Cuti Tahunan Anda ditutup karena tugas dinas mendesak. Hak yang memenuhi ketentuan dapat digunakan melalui pengajuan baru pada tahun berikutnya.',
                [
                    'leave_request_id' => $locked->id,
                    'leave_approval_id' => $approval->id,
                    'source_year' => $sourceYear,
                    'protected_days' => $protectedDays,
                    'url' => route('cuti.show', ['id' => $locked->id], false),
                ],
            );
            $auditFacts = [
                'request_id' => $locked->id,
                'employee_id' => $locked->employee_id,
                'source_year' => $sourceYear,
                'protected_days' => $protectedDays,
                'status_before' => $statusBefore,
                'status_after' => LeaveRequest::STATUS_DUTY_POSTPONED,
                'active_step_id' => $activeStep->id,
                'active_step_order' => $activeStep->step_order,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'ledger_id' => $ledger->id,
                'reservation_event_id' => $release->id,
                'protected_allocations' => $ledger->metadata['protected_allocations'] ?? [],
                'in_app_notification_recorded' => $notification !== null,
            ];

            // Audit ditulis setelah outcome notifikasi diketahui, tetapi tetap di transaksi yang sama agar keduanya rollback bersama.
            AuditLog::create([
                'user_id' => $actingUser->id,
                'user_name' => $actingUser->name,
                'event' => 'DUTY_POSTPONEMENT',
                'auditable_type' => 'LeaveRequest',
                'auditable_id' => $locked->id,
                'old_values' => [
                    'request_id' => $locked->id,
                    'employee_id' => $locked->employee_id,
                    'status' => $statusBefore,
                ],
                'new_values' => $auditFacts,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Retry hanya dianggap berhasil bila seluruh bukti terminal masih tepat satu dan saling konsisten.
     */
    private function assertExistingTerminalContract(
        LeaveRequest $request,
        Employee $actor,
        User $actingUser,
        string $expectedActiveStepId,
        int $expectedRevisionVersion,
        string $reason,
    ): void {
        $terminalSteps = $request->steps()
            ->where('status', LeaveRequestStep::STATUS_DUTY_POSTPONED)
            ->orderBy('step_order')
            ->lockForUpdate()
            ->get();
        $ledgerRows = LeaveBalanceLedger::query()
            ->where('leave_request_id', $request->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->lockForUpdate()
            ->get();
        // Satu pengajuan dapat menyimpan release lain yang sah, misalnya release rollover sebelum
        // diajukan kembali pada tahun target. Bukti terminal dibatasi ke dedup key miliknya sendiri
        // agar retry tetap idempoten dan state yang konsisten tidak dituduh korup.
        $releaseRows = LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $request->id)
            ->where('event_type', LeaveBalanceReservationEvent::EVENT_RELEASED)
            ->where('dedup_key', LeaveBalanceReservationService::dutyPostponementReleaseDedupKey(
                $request->id,
                $request->tanggal_mulai->year,
            ))
            ->lockForUpdate()
            ->get();
        $approvalRows = LeaveApproval::query()
            ->where('leave_request_id', $request->id)
            ->where('action', LeaveApproval::ACTION_DUTY_POSTPONEMENT)
            ->lockForUpdate()
            ->get();
        $auditRows = AuditLog::query()
            ->where('auditable_type', 'LeaveRequest')
            ->where('auditable_id', $request->id)
            ->where('event', 'DUTY_POSTPONEMENT')
            ->lockForUpdate()
            ->get();
        $notificationRows = SimpegNotification::query()
            ->where('user_id', $request->employee_id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('data->leave_request_id', $request->id)
            ->lockForUpdate()
            ->get();
        if ($approvalRows->count() === 1 && $approvalRows->first()?->approver_id !== $actor->id) {
            throw new AuthorizationException('Keputusan terminal ini dicatat oleh approver snapshot yang berbeda.');
        }

        if ($terminalSteps->count() !== 1
            || $ledgerRows->count() !== 1
            || $releaseRows->count() !== 1
            || $approvalRows->count() !== 1
            || $auditRows->count() !== 1
            || $request->steps()->whereIn('status', ['active', 'pending'])->exists()) {
            $this->throwCorruptedTerminalContract();
        }

        $step = $terminalSteps->first();

        if ($step->approver_employee_id !== $actor->id) {
            throw new AuthorizationException('Anda bukan approver snapshot yang berwenang untuk penangguhan tugas dinas ini.');
        }

        $this->assertExpectedActiveStep($step, $expectedActiveStepId);
        $this->assertExpectedRevisionVersion($request, $expectedRevisionVersion);
        $ledger = $ledgerRows->first();
        $release = $releaseRows->first();
        $approval = $approvalRows->first();
        $audit = $auditRows->first();
        $notification = $notificationRows->first();
        $sourceYear = $request->tanggal_mulai->year;
        $days = (int) $request->jumlah_hari_kerja;
        $allocations = $ledger?->metadata['protected_allocations'] ?? [];
        $allocationTotal = collect(['n2', 'n1', 'current'])
            ->sum(fn (string $bucket): int => max(0, (int) ($allocations[$bucket] ?? 0)));
        $auditFacts = $audit?->new_values ?? [];
        $auditOldValues = $audit?->old_values ?? [];
        $notificationRecorded = $auditFacts['in_app_notification_recorded'] ?? null;

        if (! is_bool($notificationRecorded)
            || $notificationRows->count() !== ($notificationRecorded ? 1 : 0)) {
            $this->throwCorruptedTerminalContract();
        }

        $notificationData = $notification?->data ?? [];
        $expectedNotificationData = [
            'leave_request_id' => $request->id,
            'leave_approval_id' => $approval?->id,
            'source_year' => $sourceYear,
            'protected_days' => $days,
            'url' => route('cuti.show', ['id' => $request->id], false),
        ];
        // Notifikasi terminal yang dibuat sebelum asosiasi immutable approval
        // ditambahkan tetap merupakan bukti retry yang sah bila seluruh fakta lain cocok.
        $legacyNotificationData = $expectedNotificationData;
        unset($legacyNotificationData['leave_approval_id']);

        $matches = $step?->approver_employee_id === $actor->id
            && $step?->decision_note === $reason
            && $step?->acted_at !== null
            && $request->steps()
                ->where('step_order', '>', $step->step_order)
                ->where(function ($query): void {
                    $query->where('status', '!=', 'skipped')
                        ->orWhere('skipped_reason', '!=', LeaveRequestStep::SKIPPED_DUTY_POSTPONEMENT_TERMINAL)
                        ->orWhereNull('skipped_reason');
                })
                ->doesntExist()
            && $ledger?->employee_id === $request->employee_id
            && $ledger?->source_year === $sourceYear
            && $ledger?->created_by === $actingUser->id
            && $ledger?->reason === $reason
            && ($ledger?->metadata['request_id'] ?? null) === $request->id
            && (int) ($ledger?->metadata['protected_days'] ?? 0) === $days
            && (int) ($ledger?->metadata['source_request_workdays'] ?? 0) === $days
            && ($ledger?->metadata['source_status'] ?? null) === ($auditFacts['status_before'] ?? null)
            && ($ledger?->metadata['source_status'] ?? null) === ($auditOldValues['status'] ?? null)
            && in_array($auditFacts['status_before'] ?? null, self::ACTIONABLE_STATUSES, true)
            && ($ledger?->metadata['expiry_policy'] ?? null) === 'valid_one_year_no_n2_aging'
            && $allocationTotal === $days
            && $release?->employee_id === $request->employee_id
            && $release?->leave_balance_id === $ledger?->leave_balance_id
            && $release?->tahun === $sourceYear
            && $release?->amount === -$days
            && $release?->created_by === $actingUser->id
            && ($release?->metadata['release_context'] ?? null) === 'duty_postponement_terminal'
            && $approval?->approver_id === $actor->id
            && $approval?->stage === $step?->step_order
            && $approval?->komentar === $reason
            && $approval?->acted_at !== null
            && $audit?->user_id === $actingUser->id
            && ($auditFacts['request_id'] ?? null) === $request->id
            && ($auditFacts['employee_id'] ?? null) === $request->employee_id
            && (int) ($auditFacts['source_year'] ?? 0) === $sourceYear
            && (int) ($auditFacts['protected_days'] ?? 0) === $days
            && ($auditFacts['status_after'] ?? null) === LeaveRequest::STATUS_DUTY_POSTPONED
            && ($auditFacts['active_step_id'] ?? null) === $step?->id
            && (int) ($auditFacts['active_step_order'] ?? 0) === $step?->step_order
            && ($auditFacts['actor_id'] ?? null) === $actor->id
            && ($auditFacts['reason'] ?? null) === $reason
            && ($auditFacts['ledger_id'] ?? null) === $ledger?->id
            && ($auditFacts['reservation_event_id'] ?? null) === $release?->id
            && ($auditFacts['protected_allocations'] ?? null) === $allocations
            && (! $notificationRecorded || (
                $notification?->title === 'Cuti Tahunan Ditangguhkan karena Tugas Dinas'
                && $notification?->body === 'Pengajuan Cuti Tahunan Anda ditutup karena tugas dinas mendesak. Hak yang memenuhi ketentuan dapat digunakan melalui pengajuan baru pada tahun berikutnya.'
                && ($notificationData === $expectedNotificationData
                    || $notificationData === $legacyNotificationData)
            ));

        if (! $matches) {
            $this->throwCorruptedTerminalContract();
        }
    }

    private function throwCorruptedTerminalContract(): never
    {
        throw ValidationException::withMessages([
            'leave_request' => 'Bukti penangguhan tugas dinas terminal tidak lengkap atau tidak konsisten.',
        ]);
    }

    /** Token form hanya sah untuk step snapshot yang telah dikunci dan lolos otorisasi aktor. */
    private function assertExpectedActiveStep(LeaveRequestStep $step, string $expectedActiveStepId): void
    {
        if (strtolower($step->id) !== strtolower($expectedActiveStepId)) {
            throw ValidationException::withMessages([
                'active_step_id' => 'Tahap persetujuan telah berubah. Muat ulang halaman sebelum mengirim keputusan.',
            ])->errorBag('dutyPostponement');
        }
    }

    /** Retry terminal hanya sah untuk versi request yang sama dengan keputusan pertama. */
    private function assertExpectedRevisionVersion(LeaveRequest $leaveRequest, int $expectedRevisionVersion): void
    {
        if ($leaveRequest->revision_version !== $expectedRevisionVersion) {
            throw ValidationException::withMessages([
                'revision_version' => 'Pengajuan cuti telah diperbarui. Muat ulang halaman sebelum mengirim keputusan.',
            ])->errorBag('dutyPostponement');
        }
    }
}
