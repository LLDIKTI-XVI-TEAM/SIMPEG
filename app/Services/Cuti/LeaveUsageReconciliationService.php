<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveUsageReconciliationMembership;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\AuditService;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class LeaveUsageReconciliationService
{
    public function __construct(
        private readonly LeaveBalanceRecalculationService $recalculation,
        private readonly AnnualLeaveBusinessClock $businessClock,
    ) {}

    /**
     * @param  array<int, int>  $usageByYear
     */
    public function createAnnualReconciliationSet(
        Employee $employee,
        int $balanceYear,
        array $usageByYear,
        CarbonInterface $reconciledAt,
        string $administrativeNote,
        User $actor,
        ?Request $request = null,
    ): LeaveUsageReconciliationSet {
        $reconciledAt = $this->businessClock->inBusinessTimezone($reconciledAt);
        $usage = $this->validatedUsage(
            $balanceYear,
            $usageByYear,
            $reconciledAt,
            $administrativeNote,
            true,
        );

        return DB::transaction(function () use (
            $employee,
            $balanceYear,
            $usage,
            $reconciledAt,
            $administrativeNote,
            $actor,
            $request,
        ): LeaveUsageReconciliationSet {
            $lockedEmployee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $active = LeaveUsageReconciliationSet::query()
                ->where('employee_id', $lockedEmployee->id)
                ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($active !== null && $active->balance_year >= $balanceYear) {
                throw ValidationException::withMessages([
                    'balance_year' => 'Pegawai sudah memiliki catatan pemakaian aktif untuk tahun saldo yang sama atau lebih baru.',
                ]);
            }

            $replacementReason = $active === null
                ? null
                : "Digantikan oleh catatan pemakaian tahun saldo {$balanceYear}.";

            return $this->replaceLocked(
                $lockedEmployee,
                $active,
                $balanceYear,
                $usage,
                $reconciledAt,
                $administrativeNote,
                $replacementReason,
                $actor,
                $request,
                [],
            );
        });
    }

    /**
     * @param  array<int, int>  $usageByYear
     */
    public function replaceAnnualReconciliationSet(
        LeaveUsageReconciliationSet $current,
        array $usageByYear,
        CarbonInterface $reconciledAt,
        string $administrativeNote,
        string $correctionReason,
        User $actor,
        ?Request $request = null,
        array $document = [],
    ): LeaveUsageReconciliationSet {
        $reconciledAt = $this->businessClock->inBusinessTimezone($reconciledAt);
        $usage = $this->validatedUsage(
            $current->balance_year,
            $usageByYear,
            $reconciledAt,
            $administrativeNote,
            false,
        );
        $reason = trim($correctionReason);

        if ($reason === '') {
            throw ValidationException::withMessages(['correction_reason' => 'Alasan koreksi wajib diisi.']);
        }

        return DB::transaction(function () use (
            $current,
            $usage,
            $reconciledAt,
            $administrativeNote,
            $reason,
            $actor,
            $request,
            $document,
        ): LeaveUsageReconciliationSet {
            $employee = Employee::query()->whereKey($current->employee_id)->lockForUpdate()->firstOrFail();
            $lockedCurrent = LeaveUsageReconciliationSet::query()
                ->whereKey($current->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCurrent->status !== LeaveUsageReconciliationSet::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'reconciliation_set' => 'Hanya data pemakaian aktif yang dapat diganti.',
                ]);
            }

            return $this->replaceLocked(
                $employee,
                $lockedCurrent,
                $lockedCurrent->balance_year,
                $usage,
                $reconciledAt,
                $administrativeNote,
                $reason,
                $actor,
                $request,
                $document,
            );
        });
    }

    /**
     * @param  array<int, int>  $usage
     */
    private function replaceLocked(
        Employee $employee,
        ?LeaveUsageReconciliationSet $current,
        int $balanceYear,
        array $usage,
        CarbonInterface $reconciledAt,
        string $administrativeNote,
        ?string $correctionReason,
        User $actor,
        ?Request $request,
        array $document,
    ): LeaveUsageReconciliationSet {
        $previousRecords = collect();
        $previousSetSnapshot = null;
        $projectionBefore = $this->projectionSnapshot($employee->id, min(array_keys($usage)));

        if ($current !== null) {
            $previousRecords = $current->records()->lockForUpdate()->get()->keyBy('usage_year');

            if ($previousRecords->count() !== 3) {
                throw ValidationException::withMessages([
                    'reconciliation_set' => 'Set aktif tidak memiliki tepat tiga deklarasi dan tidak aman untuk diganti.',
                ]);
            }

            $previousSetSnapshot = $this->setSnapshot($current, $previousRecords->values());

            $current->forceFill(['status' => LeaveUsageReconciliationSet::STATUS_SUPERSEDED])->save();

            foreach ($previousRecords as $previous) {
                $before = $this->factSnapshot($previous);
                $previous->forceFill([
                    'record_status' => LeaveUsageRecord::STATUS_SUPERSEDED,
                    'correction_reason' => $correctionReason,
                ])->save();
                $this->writeFactLedger(
                    LeaveBalanceLedger::EVENT_USAGE_FACT_SUPERSEDED,
                    $previous,
                    $actor,
                    $correctionReason ?? 'Digantikan oleh snapshot tahun saldo berikutnya.',
                );
                $this->auditFact(
                    'UPDATE',
                    $previous,
                    $actor,
                    $correctionReason ?? 'Digantikan oleh snapshot tahun saldo berikutnya.',
                    'usage_corrected',
                    $before,
                    $this->factSnapshot($previous),
                    $request,
                );
            }
        }

        $set = LeaveUsageReconciliationSet::create([
            'employee_id' => $employee->id,
            'balance_year' => $balanceYear,
            'reconciled_at' => $reconciledAt->toDateString(),
            'status' => LeaveUsageReconciliationSet::STATUS_ACTIVE,
            'replaces_id' => $current?->id,
            'administrative_note' => trim($administrativeNote),
            'correction_reason' => $correctionReason,
            'recorded_by' => $actor->id,
        ]);
        $annualType = RefJenisCuti::query()->where('code', 'tahunan')->firstOrFail();

        foreach ($usage as $year => $workdays) {
            // Koreksi backdated memakai akhir tahun fakta, sementara waktu koreksi tetap actual pada set.
            $cutoff = $year < $reconciledAt->year
                ? "{$year}-12-31"
                : $reconciledAt->toDateString();
            $replaced = $previousRecords->get($year);
            $record = LeaveUsageRecord::create([
                'employee_id' => $employee->id,
                'leave_type_id' => $annualType->id,
                'source_type' => LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION,
                'reconciliation_set_id' => $set->id,
                'usage_year' => $year,
                'effective_date' => $cutoff,
                'workdays' => $workdays,
                'administrative_note' => trim($administrativeNote),
                'record_status' => LeaveUsageRecord::STATUS_ACTIVE,
                'replaces_id' => $replaced?->id,
                'correction_reason' => $replaced === null ? null : $correctionReason,
                'recorded_by' => $actor->id,
            ]);

            $this->freezeMemberships($set, $record, $cutoff, $workdays, $annualType->id);
            $this->writeFactLedger(
                LeaveBalanceLedger::EVENT_USAGE_FACT_RECORDED,
                $record,
                $actor,
                $administrativeNote,
            );
            $this->auditFact(
                'CREATE',
                $record,
                $actor,
                $correctionReason ?? $administrativeNote,
                'usage_recorded',
                null,
                $this->factSnapshot($record),
                $request,
            );
        }

        if ($set->records()->count() !== 3) {
            throw new RuntimeException('Catatan pemakaian tahunan gagal membentuk tepat tiga data tahunan.');
        }

        $this->recalculation->recalculate(
            $employee,
            min(array_keys($usage)),
            $actor,
            $correctionReason ?? $administrativeNote,
            $request,
        );

        $newSetSnapshot = $this->setSnapshot($set, $set->records()->orderBy('usage_year')->get());
        $newValues = [
            'operation' => $current === null ? 'annual_reconciliation_created' : 'annual_reconciliation_replaced',
            'employee_id' => $employee->id,
            'actor_role' => $actor->role,
            'reason' => $correctionReason ?? trim($administrativeNote),
            'snapshot' => $newSetSnapshot,
            'projection_before' => $projectionBefore,
            'projection_after' => $this->projectionSnapshot($employee->id, min(array_keys($usage))),
        ];

        if ($document !== []) {
            $newValues['document'] = $document;
        }

        // Audit set ditulis terakhir agar kegagalannya membatalkan replacement, replay, dan dokumen sebagai satu unit.
        AuditService::logAsOrFail(
            $actor->id,
            (string) $actor->name,
            $current === null ? 'CREATE' : 'UPDATE',
            'LeaveUsageReconciliationSet',
            $set->id,
            $previousSetSnapshot,
            $newValues,
            $request,
        );

        return $set->fresh(['records', 'memberships']);
    }

    private function freezeMemberships(
        LeaveUsageReconciliationSet $set,
        LeaveUsageRecord $annualRecord,
        string $cutoff,
        int $declaredWorkdays,
        string $annualTypeId,
    ): void {
        $itemized = LeaveUsageRecord::query()
            ->where('employee_id', $set->employee_id)
            ->where('leave_type_id', $annualTypeId)
            ->where('usage_year', $annualRecord->usage_year)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->where('source_type', '!=', LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION)
            ->where('effective_date', '<=', $cutoff)
            ->orderBy('effective_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $included = (int) $itemized->sum('workdays');

        if ($declaredWorkdays < $included) {
            throw ValidationException::withMessages([
                "usage.{$annualRecord->usage_year}" => "Total pemakaian tahunan tidak boleh lebih kecil dari {$included} hari yang sudah tercatat satu per satu.",
            ]);
        }

        foreach ($itemized as $fact) {
            LeaveUsageReconciliationMembership::create([
                'reconciliation_set_id' => $set->id,
                'annual_reconciliation_record_id' => $annualRecord->id,
                'itemized_usage_record_id' => $fact->id,
                'included_workdays' => $fact->workdays,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $usageByYear
     * @return array<int, int>
     */
    private function validatedUsage(
        int $balanceYear,
        array $usageByYear,
        CarbonInterface $reconciledAt,
        string $administrativeNote,
        bool $mustBeRecordedInBalanceYear,
    ): array {
        $this->assertAnnualBalanceYearWithinHorizon($balanceYear);

        $expectedYears = [$balanceYear - 2, $balanceYear - 1, $balanceYear];
        $actualYears = array_map('intval', array_keys($usageByYear));
        sort($actualYears);

        if ($actualYears !== $expectedYears) {
            throw ValidationException::withMessages([
                'usage' => 'Data pemakaian wajib memuat tepat tahun N-2, N-1, dan N.',
            ]);
        }

        if ($mustBeRecordedInBalanceYear && $reconciledAt->year !== $balanceYear) {
            throw ValidationException::withMessages([
                'reconciled_at' => 'Tanggal pencatatan wajib berada pada tahun saldo.',
            ]);
        }

        if (! $mustBeRecordedInBalanceYear && $reconciledAt->year < $balanceYear) {
            throw ValidationException::withMessages([
                'reconciled_at' => 'Tanggal koreksi tidak boleh mendahului tahun saldo yang diganti.',
            ]);
        }

        if (trim($administrativeNote) === '') {
            throw ValidationException::withMessages([
                'administrative_note' => 'Keterangan atau sumber data wajib diisi.',
            ]);
        }

        $validated = [];

        foreach ($expectedYears as $year) {
            $value = $usageByYear[$year] ?? null;

            if (! is_int($value) || $value < 0) {
                throw ValidationException::withMessages([
                    "usage.{$year}" => 'Jumlah pemakaian wajib berupa bilangan bulat nol atau positif.',
                ]);
            }

            $validated[$year] = $value;
        }

        return $validated;
    }

    /** Rekonsiliasi tahunan tidak boleh membentuk fakta di luar tahun berjalan WITA. */
    private function assertAnnualBalanceYearWithinHorizon(int $balanceYear): void
    {
        if ($balanceYear <= $this->businessClock->currentYear()) {
            return;
        }

        throw ValidationException::withMessages([
            'balance_year' => 'Rekonsiliasi Cuti Tahunan tidak boleh berada setelah tahun berjalan.',
        ]);
    }

    private function writeFactLedger(
        string $event,
        LeaveUsageRecord $record,
        User $actor,
        string $reason,
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
                'reason' => trim($reason),
                'metadata' => [
                    'usage_record_id' => $record->id,
                    'source_type' => $record->source_type,
                    'record_status' => $record->record_status,
                ],
                'created_by' => $actor->id,
                'occurred_at' => now(),
            ],
        );
    }

    /** @param array<string, mixed>|null $old */
    /** @param array<string, mixed>|null $new */
    private function auditFact(
        string $event,
        LeaveUsageRecord $record,
        User $actor,
        string $reason,
        string $operation,
        ?array $old,
        ?array $new,
        ?Request $request,
    ): void {
        $newValues = array_merge($new ?? [], [
            'operation' => $operation,
            'employee_id' => $record->employee_id,
            'actor_role' => $actor->role,
            'reason' => trim($reason),
        ]);
        AuditService::logAsOrFail(
            $actor->id,
            (string) $actor->name,
            $event,
            'LeaveUsageRecord',
            $record->id,
            $old,
            $newValues,
            $request,
        );
    }

    /** @return array<string, mixed> */
    private function factSnapshot(LeaveUsageRecord $record): array
    {
        return [
            'id' => $record->id,
            'employee_id' => $record->employee_id,
            'source_type' => $record->source_type,
            'usage_year' => $record->usage_year,
            'effective_date' => $record->effective_date?->toDateString(),
            'workdays' => $record->workdays,
            'record_status' => $record->record_status,
            'replaces_id' => $record->replaces_id,
        ];
    }

    /**
     * @param  Collection<int, LeaveUsageRecord>  $records
     * @return array<string, mixed>
     */
    private function setSnapshot(LeaveUsageReconciliationSet $set, Collection $records): array
    {
        return [
            'id' => $set->id,
            'employee_id' => $set->employee_id,
            'balance_year' => $set->balance_year,
            'reconciled_at' => $set->reconciled_at?->toDateString(),
            'status' => $set->status,
            'replaces_id' => $set->replaces_id,
            'usage_by_year' => $records
                ->sortBy('usage_year')
                ->mapWithKeys(fn (LeaveUsageRecord $record): array => [
                    (string) $record->usage_year => $record->workdays,
                ])
                ->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function projectionSnapshot(string $employeeId, int $startYear): array
    {
        return LeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->whereBetween('tahun', [max(1900, $startYear), $this->businessClock->currentYear()])
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
}
