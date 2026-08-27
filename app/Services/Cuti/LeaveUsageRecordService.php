<?php

namespace App\Services\Cuti;

use App\Data\Cuti\ManualExternalApprovalStepData;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeaveUsageRecordService
{
    public function __construct(
        private readonly LeaveBalanceRecalculationService $recalculation,
        private readonly ManualExternalApprovalChainService $approvalChains,
        private readonly AnnualLeaveBusinessClock $businessClock,
    ) {}

    /**
     * Menulis fakta manual append-only beserta ledger dan audit fail-closed.
     * Replay saldo hanya dijalankan untuk jenis cuti yang mengurangi saldo tahunan.
     *
     * @param  list<ManualExternalApprovalStepData>  $approvalSteps
     * @param  array{original_name?:string,mime_type?:string,size_bytes?:int}  $document
     */
    public function recordManual(
        Employee $employee,
        RefJenisCuti $leaveType,
        string $startDate,
        string $endDate,
        int $workdays,
        string $administrativeNote,
        ?string $leaveRequestCaseId,
        ?string $approvalDocumentNumber,
        array $approvalSteps,
        User $actor,
        array $document,
        ?Request $request = null,
    ): LeaveUsageRecord {
        return DB::transaction(function () use (
            $employee,
            $leaveType,
            $startDate,
            $endDate,
            $workdays,
            $administrativeNote,
            $leaveRequestCaseId,
            $approvalDocumentNumber,
            $approvalSteps,
            $actor,
            $document,
            $request,
        ): LeaveUsageRecord {
            $year = (int) substr($startDate, 0, 4);
            $this->assertAnnualUsageYearWithinHorizon($leaveType->id, $year);
            $projectionBefore = $this->projectionSnapshot($employee->id, $year);
            $record = LeaveUsageRecord::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'source_type' => LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL,
                'reconciliation_set_id' => null,
                'leave_request_id' => null,
                'leave_request_case_id' => $leaveRequestCaseId,
                'usage_year' => $year,
                'effective_date' => $startDate,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'workdays' => $workdays,
                'administrative_note' => $administrativeNote,
                'approval_document_number' => $approvalDocumentNumber,
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'recorded_by' => $actor->id,
            ]);
            $snapshotSteps = $this->approvalChains->storeSnapshot($record, $approvalSteps);
            $this->writeLedger(
                LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
                $record,
                $actor,
                $administrativeNote,
            );

            if ($this->shouldReplayProjection($record)) {
                $this->recalculation->recalculate($employee, $year, $actor, $administrativeNote, $request);
            }

            $this->auditDomain(
                'manual_usage_recorded',
                $record,
                $actor,
                $administrativeNote,
                null,
                $this->snapshot($record),
                $projectionBefore,
                $this->projectionSnapshot($employee->id, $year),
                $document,
                $request,
                'CREATE',
                $this->approvalChains->auditPayload($snapshotSteps),
            );

            return $record->fresh();
        });
    }

    /**
     * Membuat versi fakta pengganti tanpa menghapus versi lama; ledger dan audit
     * tetap append-only, sedangkan replay hanya berjalan bila salah satu versi tahunan.
     *
     * @param  array<string, mixed>  $replacement
     * @param  list<ManualExternalApprovalStepData>  $approvalSteps
     */
    public function replace(
        LeaveUsageRecord $current,
        array $replacement,
        string $correctionReason,
        User $actor,
        array $approvalSteps,
        ?Request $request = null,
        ?array $auditContext = null,
    ): LeaveUsageRecord {
        $reason = trim($correctionReason);

        if ($reason === '') {
            throw ValidationException::withMessages(['correction_reason' => 'Alasan koreksi wajib diisi.']);
        }

        return DB::transaction(function () use ($current, $replacement, $reason, $actor, $request, $auditContext, $approvalSteps): LeaveUsageRecord {
            Employee::query()->whereKey($current->employee_id)->lockForUpdate()->firstOrFail();
            $lockedCurrent = LeaveUsageRecord::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            $this->assertItemizedActive($lockedCurrent);
            $previousApprovalSteps = $lockedCurrent->externalApprovalSteps()
                ->orderBy('step_order')
                ->get();
            $replacementLeaveTypeId = (string) ($replacement['leave_type_id'] ?? $lockedCurrent->leave_type_id);
            $replacementYear = (int) ($replacement['usage_year'] ?? $lockedCurrent->usage_year);
            $this->assertAnnualUsageYearWithinHorizon($replacementLeaveTypeId, $replacementYear);
            $before = $this->snapshot($lockedCurrent);
            $earliestYear = min($lockedCurrent->usage_year, (int) ($replacement['usage_year'] ?? $lockedCurrent->usage_year));
            $projectionBefore = $this->projectionSnapshot($lockedCurrent->employee_id, $earliestYear);
            $lockedCurrent->forceFill([
                'record_status' => LeaveUsageRecord::STATUS_SUPERSEDED,
                'correction_reason' => $reason,
            ])->save();
            $allowed = [
                'leave_type_id',
                'leave_request_case_id',
                'usage_year',
                'effective_date',
                'start_date',
                'end_date',
                'workdays',
                'administrative_note',
                'approval_document_number',
            ];
            $payload = array_merge(
                Arr::only($lockedCurrent->getRawOriginal(), [
                    'employee_id',
                    'leave_type_id',
                    'source_type',
                    'leave_request_id',
                    'leave_request_case_id',
                    'usage_year',
                    'effective_date',
                    'start_date',
                    'end_date',
                    'workdays',
                    'administrative_note',
                ]),
                Arr::only($replacement, $allowed),
                [
                    'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                    'replaces_id' => $lockedCurrent->id,
                    'correction_reason' => $reason,
                    'recorded_by' => $actor->id,
                ],
            );
            $new = LeaveUsageRecord::create($payload);
            $snapshotSteps = $this->approvalChains->storeSnapshot($new, $approvalSteps, $previousApprovalSteps);

            $this->writeLedger(LeaveBalanceLedger::EVENT_USAGE_FACT_SUPERSEDED, $lockedCurrent, $actor, $reason, [
                'replacement_id' => $new->id,
            ]);
            $this->writeLedger(LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED, $new, $actor, $reason, [
                'replaces_id' => $lockedCurrent->id,
            ]);
            if ($this->shouldReplayProjection($lockedCurrent, $new)) {
                $this->recalculation->recalculate(
                    $lockedCurrent->employee,
                    $earliestYear,
                    $actor,
                    $reason,
                    $request,
                );
            }

            if ($auditContext === null) {
                $this->audit(
                    'usage_corrected',
                    $lockedCurrent,
                    $actor,
                    $reason,
                    $before,
                    array_merge($this->snapshot($lockedCurrent), ['replacement' => $this->snapshot($new)]),
                    $request,
                );
            } else {
                $this->auditDomain(
                    (string) ($auditContext['operation'] ?? 'manual_usage_corrected'),
                    $new,
                    $actor,
                    $reason,
                    $before,
                    $this->snapshot($new),
                    $projectionBefore,
                    $this->projectionSnapshot($lockedCurrent->employee_id, $earliestYear),
                    (array) ($auditContext['document'] ?? []),
                    $request,
                    approvalSteps: $this->approvalChains->auditPayload($snapshotSteps),
                );
            }

            return $new->fresh();
        });
    }

    /**
     * Membatalkan fakta dengan perubahan status historis dan bukti ledger/audit fail-closed.
     * Saldo direplay hanya ketika fakta yang dibatalkan mengurangi saldo tahunan.
     */
    public function cancel(
        LeaveUsageRecord $current,
        string $correctionReason,
        User $actor,
        ?Request $request = null,
        ?array $auditContext = null,
    ): LeaveUsageRecord {
        $reason = trim($correctionReason);

        if ($reason === '') {
            throw ValidationException::withMessages(['correction_reason' => 'Alasan pembatalan wajib diisi.']);
        }

        return DB::transaction(function () use ($current, $reason, $actor, $request, $auditContext): LeaveUsageRecord {
            Employee::query()->whereKey($current->employee_id)->lockForUpdate()->firstOrFail();
            $lockedCurrent = LeaveUsageRecord::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            $this->assertItemizedActive($lockedCurrent);
            $before = $this->snapshot($lockedCurrent);
            $projectionBefore = $this->projectionSnapshot($lockedCurrent->employee_id, $lockedCurrent->usage_year);
            $lockedCurrent->forceFill([
                'record_status' => LeaveUsageRecord::STATUS_CANCELLED,
                'correction_reason' => $reason,
            ])->save();
            $this->writeLedger(
                LeaveBalanceLedger::EVENT_USAGE_FACT_CANCELLED,
                $lockedCurrent,
                $actor,
                $reason,
            );
            if ($this->shouldReplayProjection($lockedCurrent)) {
                $this->recalculation->recalculate(
                    $lockedCurrent->employee,
                    $lockedCurrent->usage_year,
                    $actor,
                    $reason,
                    $request,
                );
            }

            if ($auditContext === null) {
                $this->audit(
                    'usage_cancelled',
                    $lockedCurrent,
                    $actor,
                    $reason,
                    $before,
                    $this->snapshot($lockedCurrent),
                    $request,
                );
            } else {
                $this->auditDomain(
                    (string) ($auditContext['operation'] ?? 'manual_usage_cancelled'),
                    $lockedCurrent,
                    $actor,
                    $reason,
                    $before,
                    $this->snapshot($lockedCurrent),
                    $projectionBefore,
                    $this->projectionSnapshot($lockedCurrent->employee_id, $lockedCurrent->usage_year),
                    (array) ($auditContext['document'] ?? []),
                    $request,
                );
            }

            return $lockedCurrent->fresh();
        });
    }

    public function recordApprovedRequest(
        LeaveRequest $leaveRequest,
        User $actor,
        ?Request $request = null,
    ): LeaveUsageRecord {
        if (! $leaveRequest->exists) {
            throw ValidationException::withMessages([
                'leave_request' => 'Pengajuan cuti wajib tersimpan sebelum dicatat sebagai pemakaian.',
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $request): LeaveUsageRecord {
            $employee = Employee::query()
                ->whereKey($leaveRequest->employee_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRequest = LeaveRequest::query()
                ->with('jenisCuti')
                ->whereKey($leaveRequest->id)
                ->firstOrFail();
            $existing = LeaveUsageRecord::query()
                ->where('leave_request_id', $lockedRequest->id)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            if ($lockedRequest->status !== 'disetujui') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya pengajuan berstatus Disetujui yang menjadi fakta pemakaian.',
                ]);
            }

            if ($lockedRequest->tanggal_mulai->year !== $lockedRequest->tanggal_selesai->year) {
                throw ValidationException::withMessages([
                    'tanggal_selesai' => 'Fakta pemakaian tidak boleh melintasi tahun kalender.',
                ]);
            }

            if ($lockedRequest->jumlah_hari_kerja <= 0) {
                throw ValidationException::withMessages([
                    'jumlah_hari_kerja' => 'Jumlah hari kerja pemakaian wajib lebih dari nol.',
                ]);
            }

            $this->assertAnnualUsageYearWithinHorizon(
                $lockedRequest->jenis_cuti_id,
                $lockedRequest->tanggal_mulai->year,
            );

            $record = LeaveUsageRecord::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $lockedRequest->jenis_cuti_id,
                'source_type' => LeaveUsageRecord::SOURCE_APPROVED_REQUEST,
                'leave_request_id' => $lockedRequest->id,
                'leave_request_case_id' => $lockedRequest->leave_request_case_id,
                'usage_year' => $lockedRequest->tanggal_mulai->year,
                'effective_date' => $lockedRequest->tanggal_mulai->toDateString(),
                'start_date' => $lockedRequest->tanggal_mulai->toDateString(),
                'end_date' => $lockedRequest->tanggal_selesai->toDateString(),
                'workdays' => $lockedRequest->jumlah_hari_kerja,
                'administrative_note' => 'Persetujuan final pengajuan SIMPEG: '.$lockedRequest->alasan,
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'recorded_by' => $actor->id,
            ]);
            $this->writeLedger(
                LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
                $record,
                $actor,
                'Pengajuan SIMPEG mencapai persetujuan final.',
            );
            $this->audit(
                'usage_recorded',
                $record,
                $actor,
                'Pengajuan SIMPEG mencapai persetujuan final.',
                null,
                $this->snapshot($record),
                $request,
                'CREATE',
            );
            if ($this->shouldReplayProjection($record)) {
                $this->recalculation->recalculate(
                    $employee,
                    $record->usage_year,
                    $actor,
                    'Pengajuan SIMPEG mencapai persetujuan final.',
                    $request,
                );
            }

            return $record->fresh();
        });
    }

    private function assertItemizedActive(LeaveUsageRecord $record): void
    {
        if ($record->source_type !== LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL) {
            throw ValidationException::withMessages([
                'usage_record' => 'Koreksi langsung hanya tersedia untuk cuti manual eksternal.',
            ]);
        }

        if ($record->record_status !== LeaveUsageRecord::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'usage_record' => 'Hanya fakta pemakaian aktif yang dapat dikoreksi atau dibatalkan.',
            ]);
        }
    }

    /**
     * Menjalankan replay untuk fakta tahunan, atau fakta Cuti Besar yang dapat
     * memengaruhi hak efektif pada projection yang sudah memiliki baseline.
     */
    private function shouldReplayProjection(LeaveUsageRecord ...$records): bool
    {
        foreach ($records as $record) {
            if ($this->isAnnual($record->leave_type_id)) {
                return true;
            }
        }

        $largeFact = collect($records)->first(
            fn (LeaveUsageRecord $record): bool => $record->workdays > 0
                && $this->isCutiBesar($record->leave_type_id),
        );

        if (! $largeFact instanceof LeaveUsageRecord) {
            return false;
        }

        return LeaveBalance::query()->where('employee_id', $largeFact->employee_id)->exists()
            || LeaveUsageRecord::query()
                ->where('employee_id', $largeFact->employee_id)
                ->where('source_type', LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION)
                ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
                ->exists();
    }

    private function isCutiBesar(string $leaveTypeId): bool
    {
        return RefJenisCuti::query()->whereKey($leaveTypeId)->where('code', 'besar')->exists();
    }

    /** @param array<string, mixed> $extra */
    private function writeLedger(
        string $event,
        LeaveUsageRecord $record,
        User $actor,
        string $reason,
        array $extra = [],
    ): void {
        LeaveBalanceLedger::query()->firstOrCreate(
            ['dedup_key' => "usage:{$event}:{$record->id}"],
            [
                'employee_id' => $record->employee_id,
                'leave_request_id' => $record->leave_request_id,
                'tahun' => $record->usage_year,
                'event_type' => $event,
                'amount' => $record->workdays,
                'source_year' => $record->usage_year,
                'reason' => $reason,
                'metadata' => array_merge([
                    'usage_record_id' => $record->id,
                    'source_type' => $record->source_type,
                    'record_status' => $record->record_status,
                ], $extra),
                'created_by' => $actor->id,
                'occurred_at' => now(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function audit(
        string $operation,
        LeaveUsageRecord $record,
        User $actor,
        string $reason,
        ?array $old,
        ?array $new,
        ?Request $request,
        string $event = 'UPDATE',
    ): void {
        AuditService::logAsOrFail(
            $actor->id,
            (string) $actor->name,
            $event,
            'LeaveUsageRecord',
            $record->id,
            $old,
            array_merge($new ?? [], [
                'operation' => $operation,
                'employee_id' => $record->employee_id,
                'actor_role' => $actor->role,
                'reason' => $reason,
            ]),
            $request,
        );
    }

    /**
     * @param  array<string, mixed>|null  $factBefore
     * @param  array<string, mixed>|null  $factAfter
     * @param  list<array<string, mixed>>  $projectionBefore
     * @param  list<array<string, mixed>>  $projectionAfter
     * @param  array<string, mixed>  $document
     * @param  list<array<string, mixed>>  $approvalSteps
     */
    private function auditDomain(
        string $operation,
        LeaveUsageRecord $record,
        User $actor,
        string $reason,
        ?array $factBefore,
        ?array $factAfter,
        array $projectionBefore,
        array $projectionAfter,
        array $document,
        ?Request $request,
        string $event = 'UPDATE',
        array $approvalSteps = [],
    ): void {
        AuditService::logAsOrFail(
            $actor->id,
            (string) $actor->name,
            $event,
            'LeaveUsageRecord',
            $record->id,
            $factBefore,
            [
                'operation' => $operation,
                'employee_id' => $record->employee_id,
                'actor_role' => $actor->role,
                'reason' => $reason,
                'document' => $document,
                'fact_before' => $factBefore,
                'fact_after' => $factAfter,
                'projection_before' => $projectionBefore,
                'projection_after' => $projectionAfter,
                'approval_steps' => $approvalSteps,
            ],
            $request,
        );
    }

    private function isAnnual(string $leaveTypeId): bool
    {
        return RefJenisCuti::query()
            ->whereKey($leaveTypeId)
            ->where('code', RefJenisCuti::CODE_TAHUNAN)
            ->exists();
    }

    /**
     * Projection saldo tidak boleh dibentuk dari fakta tahun yang belum berjalan.
     * Tanggal setelah hari ini tetap sah selama masih berada pada tahun WITA berjalan.
     */
    private function assertAnnualUsageYearWithinHorizon(string $leaveTypeId, int $usageYear): void
    {
        if (! $this->isAnnual($leaveTypeId)
            || $usageYear <= $this->businessClock->currentYear()) {
            return;
        }

        throw ValidationException::withMessages([
            'usage_year' => 'Fakta Cuti Tahunan tidak boleh berada setelah tahun berjalan.',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function projectionSnapshot(string $employeeId, int $affectedYear): array
    {
        return LeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('tahun', [max(1900, $affectedYear - 2), $this->businessClock->currentYear()])
            ->orderBy('tahun')
            ->get([
                'tahun',
                'jatah_awal',
                'carry_over',
                'terpakai',
                'sisa',
                'sisa_n2',
                'sisa_n1',
                'sisa_tahun_berjalan',
                'hangus',
            ])
            ->map(fn (LeaveBalance $balance): array => $balance->toArray())
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function snapshot(LeaveUsageRecord $record): array
    {
        return [
            'id' => $record->id,
            'employee_id' => $record->employee_id,
            'leave_type_id' => $record->leave_type_id,
            'source_type' => $record->source_type,
            'leave_request_id' => $record->leave_request_id,
            'leave_request_case_id' => $record->leave_request_case_id,
            'usage_year' => $record->usage_year,
            'effective_date' => $record->effective_date?->toDateString(),
            'start_date' => $record->start_date?->toDateString(),
            'end_date' => $record->end_date?->toDateString(),
            'workdays' => $record->workdays,
            'administrative_note' => $record->administrative_note,
            'approval_document_number' => $record->approval_document_number,
            'record_status' => $record->record_status,
            'replaces_id' => $record->replaces_id,
            'correction_reason' => $record->correction_reason,
            'recorded_by' => $record->recorded_by,
        ];
    }
}
