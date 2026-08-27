<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveUsageReconciliationMembership;
use App\Models\LeaveUsageReconciliationSet;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeaveBalanceRecalculationService
{
    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly AnnualLeaveCeilingService $ceiling,
        private readonly AnnualLeaveEligibilityPolicy $annualEligibility,
        private readonly AnnualLeaveBusinessClock $businessClock,
        private readonly EmploymentStartDateResolver $employmentStartDate,
    ) {}

    /** @return Collection<int, LeaveBalance> */
    public function recalculate(
        Employee $employee,
        int $earliestAffectedYear,
        User $actor,
        string $reason,
        ?Request $httpRequest = null,
    ): Collection {
        return $this->run(
            $employee,
            $earliestAffectedYear,
            trim($reason),
            $actor,
            null,
            $httpRequest,
        );
    }

    /**
     * Replay perubahan syarat kepegawaian memakai horizon material aktif tanpa menjadikan fakta lama sebagai anchor baru.
     * Jalur ini mencakup TMT efektif dan input kontrak PPPK yang memengaruhi plafon cuti tahunan.
     *
     * @return Collection<int, LeaveBalance>
     */
    public function recalculateForEmploymentTermsChange(
        Employee $employee,
        int $materialStartYear,
        ?Carbon $effectiveTmtBefore,
        User $actor,
        string $reason,
        ?Request $httpRequest = null,
    ): Collection {
        return $this->run(
            $employee,
            $materialStartYear,
            trim($reason),
            $actor,
            null,
            $httpRequest,
            false,
            false,
            $effectiveTmtBefore,
        );
    }

    /** @return Collection<int, LeaveBalance> */
    public function recalculateForSystem(
        Employee $employee,
        int $earliestAffectedYear,
        string $reason,
        string $systemActor,
    ): Collection {
        if ($systemActor !== AuditService::SYSTEM_SCHEDULER) {
            throw ValidationException::withMessages(['system_actor' => 'Aktor sistem tidak diizinkan.']);
        }

        return $this->run(
            $employee,
            $earliestAffectedYear,
            trim($reason),
            null,
            $systemActor,
            null,
        );
    }

    /**
     * Replay rollover mempertahankan predecessor material hanya saat belum ada
     * set pemakaian aktif; set aktif tetap menjadi anchor horizon perhitungan.
     *
     * @return Collection<int, LeaveBalance>
     */
    public function recalculateForRollover(
        Employee $employee,
        int $sourceYear,
        string $reason,
        string $systemActor,
    ): Collection {
        if ($systemActor !== AuditService::SYSTEM_SCHEDULER) {
            throw ValidationException::withMessages(['system_actor' => 'Aktor sistem tidak diizinkan.']);
        }

        return $this->run(
            employee: $employee,
            earliestAffectedYear: $sourceYear,
            reason: trim($reason),
            actor: null,
            systemActor: $systemActor,
            httpRequest: null,
            useMaterialPredecessorWithoutActiveSet: true,
        );
    }

    /** @return Collection<int, LeaveBalance> */
    public function recalculateForDatabaseUpgrade(
        Employee $employee,
        int $earliestAffectedYear,
        string $reason,
    ): Collection {
        return $this->run(
            $employee,
            $earliestAffectedYear,
            trim($reason),
            null,
            AuditService::SYSTEM_DATABASE_UPGRADE,
            null,
            true,
            true,
        );
    }

    /**
     * @return Collection<int, LeaveBalance>
     */
    private function run(
        Employee $employee,
        int $earliestAffectedYear,
        string $reason,
        ?User $actor,
        ?string $systemActor,
        ?Request $httpRequest,
        bool $historicalFactsMayExpandHorizon = true,
        bool $useMaterialPredecessor = false,
        ?Carbon $effectiveTmtBefore = null,
        bool $useMaterialPredecessorWithoutActiveSet = false,
    ): Collection {
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan rekalkulasi wajib diisi.']);
        }

        return DB::transaction(function () use (
            $employee,
            $earliestAffectedYear,
            $reason,
            $actor,
            $systemActor,
            $httpRequest,
            $historicalFactsMayExpandHorizon,
            $useMaterialPredecessor,
            $effectiveTmtBefore,
            $useMaterialPredecessorWithoutActiveSet,
        ): Collection {
            $employeeQuery = Employee::query()
                ->with(['jenisPegawai', 'appointments']);

            if ($systemActor === AuditService::SYSTEM_DATABASE_UPGRADE) {
                // Hanya upgrade database yang boleh melewati scope legacy; aman saat lifecycle baru tidak memasangnya.
                $employeeQuery->withoutGlobalScope(SoftDeletingScope::class);
            }

            // Mutex pegawai menjadi lock pertama agar semua mutation saldo terserialisasi konsisten.
            $lockedEmployee = $employeeQuery
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();
            $activeSet = LeaveUsageReconciliationSet::query()
                ->where('employee_id', $lockedEmployee->id)
                ->where('status', LeaveUsageReconciliationSet::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();
            $facts = LeaveUsageRecord::query()
                ->where('employee_id', $lockedEmployee->id)
                ->orderBy('effective_date')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $memberships = $activeSet === null
                ? collect()
                : LeaveUsageReconciliationMembership::query()
                    ->where('reconciliation_set_id', $activeSet->id)
                    ->lockForUpdate()
                    ->get();
            $annualTypeId = RefJenisCuti::query()->where('code', 'tahunan')->value('id');

            if ($annualTypeId === null) {
                throw ValidationException::withMessages(['jenis_cuti' => 'Referensi Cuti Tahunan belum tersedia.']);
            }

            $startYear = $this->startYear(
                $facts,
                $activeSet,
                $earliestAffectedYear,
                $annualTypeId,
                $historicalFactsMayExpandHorizon,
            );
            $endYear = $this->businessClock->currentYear();

            if ($startYear > $endYear) {
                return collect();
            }

            $rebuildVirtualPredecessor = ! $historicalFactsMayExpandHorizon
                && $this->eligibilityChangesInPredecessorHorizon(
                    $lockedEmployee,
                    $effectiveTmtBefore,
                    $startYear,
                );
            $materialPredecessorYear = $startYear > 1900 ? $startYear - 1 : null;
            $balanceQueryStartYear = $materialPredecessorYear ?? $startYear;
            $existingBalances = LeaveBalance::query()
                ->where('employee_id', $lockedEmployee->id)
                ->whereBetween('tahun', [$balanceQueryStartYear, $endYear])
                ->orderBy('tahun')
                ->lockForUpdate()
                ->get()
                ->keyBy('tahun');
            $projectionStartYear = $startYear;
            if (! $historicalFactsMayExpandHorizon && $rebuildVirtualPredecessor) {
                $predecessorYear = $startYear - 1;
                if ($this->canBuildVirtualPredecessor($lockedEmployee, $predecessorYear)) {
                    // Maksimal tiga tahun asal cukup untuk membentuk bucket N-2/N-1/current predecessor.
                    $projectionStartYear = max(1900, $startYear - 3);
                }
            }
            $reservations = LeaveBalanceReservationEvent::query()
                ->forActiveRequests()
                ->where('employee_id', $lockedEmployee->id)
                ->whereBetween('tahun', [$projectionStartYear, $endYear])
                ->orderBy('tahun')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get()
                ->groupBy('tahun')
                ->map(fn (Collection $events): int => max(0, (int) $events->sum('amount')));
            $usageStartYear = $historicalFactsMayExpandHorizon
                ? $startYear
                : min($projectionStartYear, $startYear - 2);
            $usage = $this->usageByYear(
                $facts,
                $memberships,
                $activeSet,
                $annualTypeId,
                $usageStartYear,
                $endYear,
            );
            $projections = [];
            $previous = null;
            $mayUseMaterialPredecessor = $useMaterialPredecessor
                || ($useMaterialPredecessorWithoutActiveSet && $activeSet === null)
                || (! $historicalFactsMayExpandHorizon && ! $rebuildVirtualPredecessor);

            if ($mayUseMaterialPredecessor && $materialPredecessorYear !== null) {
                $materialPredecessor = $existingBalances->get($materialPredecessorYear);
                if ($materialPredecessor instanceof LeaveBalance) {
                    $previous = $this->validatedMaterialPredecessor(
                        $lockedEmployee,
                        $materialPredecessor,
                        $materialPredecessorYear,
                    );
                }
            }

            for ($year = $projectionStartYear; $year <= $endYear; $year++) {
                $yearUsage = $usage[$year]['workdays'];
                $entitlementAsOf = $year === $endYear
                    ? $this->businessClock->now()
                    : Carbon::parse("{$year}-12-31")->endOfDay();
                $annualEntitlement = $this->annualEligibility->isEligible($lockedEmployee, $entitlementAsOf)
                    ? $this->calculator->annualEntitlement()
                    : 0;

                if ($annualEntitlement === 0) {
                    // Tahun yang belum memenuhi masa kerja tidak boleh menerima hak atau carry virtual.
                    $opening = [
                        'n2' => 0,
                        'n1' => 0,
                        'current' => 0,
                        'hangus' => 0,
                    ];
                } elseif ($previous === null) {
                    $opening = [
                        'n2' => 0,
                        'n1' => 0,
                        'current' => $annualEntitlement,
                        'hangus' => 0,
                    ];
                } else {
                    $opening = $this->openingFromPreviousYear(
                        $lockedEmployee,
                        $year,
                        $previous,
                        $usage[$year - 2]['workdays'] ?? 0,
                        $usage[$year - 1]['workdays'] ?? 0,
                    );
                }

                $allocation = $this->calculator->allocateDeduction([
                    'n2' => $opening['n2'],
                    'n1' => $opening['n1'],
                    'current' => $opening['current'],
                ], $yearUsage);

                if (! $allocation['success']) {
                    throw ValidationException::withMessages([
                        "usage.{$year}" => "Pemakaian {$yearUsage} hari melebihi hak cuti yang dapat direplay pada {$year}.",
                    ]);
                }

                $remaining = $allocation['remaining'];
                $protected = $this->protectedAllocations($lockedEmployee->id, $year);
                $this->assertProtectedAllocationsRemain($year, $remaining, $protected);
                $projection = [
                    'tahun' => $year,
                    'jatah_awal' => $annualEntitlement,
                    'carry_over' => $opening['n2'] + $opening['n1'],
                    'terpakai' => $yearUsage,
                    'sisa' => $this->calculator->availableTotal($remaining),
                    'sisa_n2' => $remaining['n2'],
                    'sisa_n1' => $remaining['n1'],
                    'sisa_tahun_berjalan' => $remaining['current'],
                    'terpakai_tahun_berjalan' => $allocation['allocations']['current'],
                    'hangus' => $opening['hangus'],
                    'ordered_fact_ids' => $usage[$year]['ordered_fact_ids'],
                ];
                $reserved = (int) ($reservations[$year] ?? 0);
                $effectiveAvailable = $this->hasApprovedCutiBesar($lockedEmployee->id, $year)
                    ? 0
                    : $projection['sisa'] - array_sum($protected);

                if ($effectiveAvailable < $reserved) {
                    throw ValidationException::withMessages([
                        'saldo' => "Rekalkulasi menyisakan {$effectiveAvailable} hari efektif pada {$year}, lebih kecil dari reservasi aktif {$reserved} hari.",
                    ]);
                }

                $projections[$year] = $projection;
                $previous = $projection;
            }

            $result = collect();

            foreach ($projections as $year => $projection) {
                if ($year < $startYear) {
                    // Predecessor virtual hanya menjadi opening; row, ledger, dan audit historis tetap immutable.
                    continue;
                }

                $balance = $existingBalances->get($year);
                $old = $balance === null ? null : $this->balanceSnapshot($balance);
                $attributes = collect($projection)->except('ordered_fact_ids')->all();
                $new = $attributes;

                if ($old !== null && $old === $new) {
                    $result->push($balance);

                    continue;
                }

                if ($balance === null) {
                    $balance = LeaveBalance::create(array_merge([
                        'employee_id' => $lockedEmployee->id,
                    ], $attributes));
                } else {
                    $balance->forceFill($attributes)->save();
                }

                $sourceState = [
                    'active_set' => $activeSet === null ? null : [
                        'id' => $activeSet->id,
                        'balance_year' => $activeSet->balance_year,
                        'status' => $activeSet->status,
                        'updated_at' => $activeSet->updated_at?->toISOString(),
                    ],
                    'facts' => $facts
                        ->where('usage_year', '<=', $year)
                        ->map(fn (LeaveUsageRecord $fact): array => [
                            'id' => $fact->id,
                            'usage_year' => $fact->usage_year,
                            'workdays' => $fact->workdays,
                            'record_status' => $fact->record_status,
                            'replaces_id' => $fact->replaces_id,
                            'updated_at' => $fact->updated_at?->toISOString(),
                        ])
                        ->values()
                        ->all(),
                ];
                $hash = hash('sha256', json_encode([
                    'projection' => $new,
                    'ordered_fact_ids' => $projection['ordered_fact_ids'],
                    'source_state' => $sourceState,
                ], JSON_THROW_ON_ERROR));
                LeaveBalanceLedger::query()->firstOrCreate(
                    ['dedup_key' => "recalc:{$lockedEmployee->id}:{$year}:{$hash}"],
                    [
                        'employee_id' => $lockedEmployee->id,
                        'leave_balance_id' => $balance->id,
                        'tahun' => $year,
                        'event_type' => LeaveBalanceLedger::EVENT_BALANCE_RECALCULATED,
                        'amount' => $new['sisa'] - ($old['sisa'] ?? 0),
                        'source_year' => $year,
                        'reason' => $reason,
                        'metadata' => [
                            'before' => $old,
                            'after' => $new,
                            'ordered_fact_ids' => $projection['ordered_fact_ids'],
                            'source_hash' => $hash,
                        ],
                        'created_by' => $actor?->id,
                        'occurred_at' => now(),
                    ],
                );

                if ($new['hangus'] > 0) {
                    LeaveBalanceLedger::query()->firstOrCreate(
                        ['dedup_key' => "expiry:{$lockedEmployee->id}:{$year}:{$hash}"],
                        [
                            'employee_id' => $lockedEmployee->id,
                            'leave_balance_id' => $balance->id,
                            'tahun' => $year,
                            'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED,
                            'amount' => 0,
                            'source_year' => $year - 1,
                            'reason' => 'Sisa hak melewati batas carry-over berdasarkan hasil rekalkulasi.',
                            'metadata' => [
                                'expired_days' => $new['hangus'],
                                'target_year' => $year,
                                'source_hash' => $hash,
                            ],
                            'created_by' => $actor?->id,
                            'occurred_at' => now(),
                        ],
                    );
                }

                $auditNew = [
                    'operation' => 'balance_recalculated',
                    'employee_id' => $lockedEmployee->id,
                    'actor_role' => $actor?->role,
                    'reason' => $reason,
                    'before' => $old,
                    'after' => $new,
                ];

                if ($actor !== null) {
                    AuditService::logAsOrFail(
                        $actor->id,
                        (string) $actor->name,
                        'UPDATE',
                        'LeaveBalance',
                        $balance->id,
                        $old,
                        $auditNew,
                        $httpRequest,
                    );
                } elseif ($systemActor === AuditService::SYSTEM_DATABASE_UPGRADE) {
                    AuditService::logDatabaseUpgradeOrFail(
                        'UPDATE',
                        'LeaveBalance',
                        $balance->id,
                        $old,
                        $auditNew,
                    );
                } else {
                    AuditService::logSystemOrFail(
                        (string) $systemActor,
                        'UPDATE',
                        'LeaveBalance',
                        $balance->id,
                        $old,
                        $auditNew,
                    );
                }

                $result->push($balance->fresh());
            }

            return $result;
        });
    }

    /**
     * @param  Collection<int, LeaveUsageRecord>  $facts
     */
    private function startYear(
        Collection $facts,
        ?LeaveUsageReconciliationSet $activeSet,
        int $earliestAffectedYear,
        string $annualTypeId,
        bool $historicalFactsMayExpandHorizon,
    ): int {
        // Snapshot aktif selalu menjadi anchor agar fakta historis di luar N-2/N-1/N tidak menciptakan carry baru.
        if ($activeSet !== null) {
            return $activeSet->balance_year - 2;
        }

        // Koreksi TMT harus mereplay projection material, bukan membentuk ulang seluruh sejarah fakta.
        if (! $historicalFactsMayExpandHorizon) {
            return $earliestAffectedYear;
        }

        $factYear = $facts
            ->where('source_type', '!=', LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION)
            ->where('leave_type_id', $annualTypeId)
            ->min('usage_year');

        return min(array_filter([
            $earliestAffectedYear,
            $factYear === null ? null : (int) $factYear,
        ], fn (?int $year): bool => $year !== null));
    }

    /**
     * @param  Collection<int, LeaveUsageRecord>  $facts
     * @param  Collection<int, LeaveUsageReconciliationMembership>  $memberships
     * @return array<int, array{workdays:int, ordered_fact_ids:list<string>}>
     */
    private function usageByYear(
        Collection $facts,
        Collection $memberships,
        ?LeaveUsageReconciliationSet $activeSet,
        string $annualTypeId,
        int $startYear,
        int $endYear,
    ): array {
        $children = $facts->whereNotNull('replaces_id')->keyBy('replaces_id');
        $factsById = $facts->keyBy('id');
        $declarations = $activeSet === null
            ? collect()
            : $facts->where('reconciliation_set_id', $activeSet->id)
                ->where('source_type', LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION)
                ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
                ->keyBy('usage_year');
        $covered = [];
        $membershipAdjustments = [];

        foreach ($memberships as $membership) {
            $annual = $factsById->get($membership->annual_reconciliation_record_id);
            $terminal = $factsById->get($membership->itemized_usage_record_id);

            while ($terminal !== null) {
                $covered[$terminal->id] = true;
                $child = $children->get($terminal->id);

                if ($child === null) {
                    break;
                }

                $terminal = $child;
            }

            if ($annual !== null) {
                $membershipAdjustments[$annual->usage_year] = ($membershipAdjustments[$annual->usage_year] ?? 0)
                    - $membership->included_workdays;
            }

            if ($terminal?->record_status === LeaveUsageRecord::STATUS_ACTIVE
                && $terminal->leave_type_id === $annualTypeId
                && $terminal->source_type !== LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION) {
                $membershipAdjustments[$terminal->usage_year] = ($membershipAdjustments[$terminal->usage_year] ?? 0)
                    + $terminal->workdays;
            }
        }

        $result = [];

        for ($year = $startYear; $year <= $endYear; $year++) {
            $activeItemized = $facts
                ->where('usage_year', $year)
                ->where('leave_type_id', $annualTypeId)
                ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
                ->where('source_type', '!=', LeaveUsageRecord::SOURCE_ANNUAL_RECONCILIATION);
            $newFacts = $activeItemized->reject(fn (LeaveUsageRecord $fact): bool => isset($covered[$fact->id]));
            $workdays = (int) ($declarations->get($year)?->workdays ?? 0)
                + (int) ($membershipAdjustments[$year] ?? 0)
                + (int) $newFacts->sum('workdays');

            if ($workdays < 0) {
                throw ValidationException::withMessages([
                    "usage.{$year}" => 'Delta koreksi menghasilkan pemakaian negatif dan menandakan lineage tidak konsisten.',
                ]);
            }

            $result[$year] = [
                'workdays' => $workdays,
                'ordered_fact_ids' => $activeItemized->pluck('id')->values()->all(),
            ];
        }

        return $result;
    }

    /**
     * Predecessor TMT-change selalu dibentuk dari TMT dan fakta terkini karena row material dapat
     * merekam horizon TMT lama meski jatah tahun predecessor sendiri sudah nonzero.
     */
    private function canBuildVirtualPredecessor(
        Employee $employee,
        int $predecessorYear,
    ): bool {
        return $this->annualEligibility->isEligible(
            $employee,
            Carbon::parse("{$predecessorYear}-12-31")->endOfDay(),
        );
    }

    /**
     * Replay TMT hanya membangun predecessor virtual bila koreksi mengubah tahun eligibility.
     * Pergeseran tanggal dalam tahun eligibility yang sama tidak boleh menimpa carry material.
     */
    private function eligibilityChangesInPredecessorHorizon(
        Employee $employee,
        ?Carbon $effectiveTmtBefore,
        int $startYear,
    ): bool {
        $effectiveTmtAfter = $this->employmentStartDate->earliestAppointmentTmt($employee);
        $firstYear = max(1900, $startYear - 3);

        for ($year = $firstYear; $year < $startYear; $year++) {
            if ($this->eligibleAtYearEnd($effectiveTmtBefore, $year)
                !== $this->eligibleAtYearEnd($effectiveTmtAfter, $year)) {
                return true;
            }
        }

        return false;
    }

    private function eligibleAtYearEnd(?Carbon $tmt, int $year): bool
    {
        return $tmt !== null
            && $tmt->copy()->addYearNoOverflow()->toDateString() <= "{$year}-12-31";
    }

    /**
     * Opening legacy/material hanya dipercaya bila seluruh total bucket dan entitlement konsisten.
     * Data ambigu dihentikan agar replay tidak menghasilkan carry yang tidak dapat ditelusuri.
     *
     * @return array<string, int>
     */
    private function validatedMaterialPredecessor(
        Employee $employee,
        LeaveBalance $balance,
        int $expectedYear,
    ): array {
        $snapshot = $this->balanceSnapshot($balance);
        $expectedEntitlement = $this->annualEligibility->isEligible(
            $employee,
            Carbon::parse("{$expectedYear}-12-31")->endOfDay(),
        ) ? $this->calculator->annualEntitlement() : 0;
        $remaining = $snapshot['sisa_n2'] + $snapshot['sisa_n1'] + $snapshot['sisa_tahun_berjalan'];

        if ($snapshot['tahun'] !== $expectedYear
            || $snapshot['jatah_awal'] !== $expectedEntitlement
            || $snapshot['carry_over'] !== $snapshot['sisa_n2'] + $snapshot['sisa_n1']
            || $snapshot['sisa'] !== $remaining
            || $snapshot['jatah_awal'] + $snapshot['carry_over'] !== $snapshot['terpakai'] + $snapshot['sisa']) {
            throw ValidationException::withMessages([
                'saldo' => "Projection predecessor {$expectedYear} tidak konsisten dan tidak aman dipakai sebagai opening replay.",
            ]);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $previous
     * @return array{n2:int, n1:int, current:int, hangus:int}
     */
    private function openingFromPreviousYear(
        Employee $employee,
        int $year,
        array $previous,
        int $usageN2,
        int $usageN1,
    ): array {
        $sourceYear = $year - 1;
        $protected = $this->protectedAllocations($employee->id, $sourceYear);
        $previousBuckets = [
            'n2' => (int) $previous['sisa_n2'],
            'n1' => (int) $previous['sisa_n1'],
            'current' => (int) $previous['sisa_tahun_berjalan'],
        ];

        foreach (['n2', 'n1', 'current'] as $bucket) {
            if ($protected[$bucket] > $previousBuckets[$bucket]) {
                throw ValidationException::withMessages([
                    'saldo' => "Alokasi terlindungi bucket {$bucket} tidak konsisten dengan projection {$sourceYear}.",
                ]);
            }
        }

        $rule5Current = $this->hasApprovedCutiBesar($employee->id, $sourceYear)
            ? $previousBuckets['current'] - $protected['current']
            : 0;
        $ordinaryN1 = $previousBuckets['n1'] - $protected['n1'];
        $expiringDutyCarry = $this->remainingDutyCarryExpiringIn(
            $employee->id,
            $sourceYear,
            $ordinaryN1,
        );
        $ordinaryN1 -= $expiringDutyCarry;
        $ordinaryCurrent = $previousBuckets['current'] - $protected['current'] - $rule5Current;
        $maximum = $this->ceiling->maximumFor($employee, $year, $usageN2, $usageN1);
        $rollover = $this->calculator->calculateRolloverFromUsage(
            $ordinaryN1,
            $ordinaryCurrent,
            $usageN2,
            $usageN1,
            $maximum,
        );
        $protectedTotal = array_sum($protected);
        $carryRoom = $maximum - $rollover['current'] - $rollover['n2'] - $rollover['n1'];

        // Hak statutory dipertahankan penuh; bila perlu carry ordinary yang lebih muda yang hangus.
        foreach (['n1', 'n2'] as $bucket) {
            if ($protectedTotal <= $carryRoom) {
                break;
            }

            $released = min($rollover[$bucket], $protectedTotal - $carryRoom);
            $rollover[$bucket] -= $released;
            $rollover['hangus'] += $released;
            $carryRoom += $released;
        }

        if ($protectedTotal > $carryRoom) {
            throw ValidationException::withMessages([
                'saldo' => "Hak penangguhan dinas {$sourceYear} tidak muat pada ceiling tahun {$year}.",
            ]);
        }

        $rollover['n1'] += $protectedTotal;
        $rollover['hangus'] += ($previousBuckets['n2'] - $protected['n2'])
            + $rule5Current
            + $expiringDutyCarry;

        return [
            'n2' => $rollover['n2'],
            'n1' => $rollover['n1'],
            'current' => $rollover['current'],
            'hangus' => $rollover['hangus'],
        ];
    }

    /** @return array{n2:int, n1:int, current:int} */
    private function protectedAllocations(string $employeeId, int $sourceYear): array
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('source_year', $sourceYear)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->get(['metadata'])
            ->reduce(function (array $total, LeaveBalanceLedger $ledger): array {
                $allocations = $ledger->metadata['protected_allocations'] ?? [];

                foreach (['n2', 'n1', 'current'] as $bucket) {
                    $total[$bucket] += max(0, (int) ($allocations[$bucket] ?? 0));
                }

                return $total;
            }, ['n2' => 0, 'n1' => 0, 'current' => 0]);
    }

    /**
     * Hak penangguhan dinas hanya hidup sebagai N-1 selama satu tahun dan tidak boleh menua ke N-2.
     * Sisa N-1 diatribusikan ke hak statutory lebih dahulu agar hari yang sudah dipakai tidak dihanguskan ulang.
     */
    private function remainingDutyCarryExpiringIn(string $employeeId, int $sourceYear, int $liveN1): int
    {
        $granted = LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('source_year', $sourceYear - 1)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->get(['metadata'])
            ->sum(function (LeaveBalanceLedger $ledger): int {
                $metadata = $ledger->metadata ?? [];

                if (($metadata['expiry_policy'] ?? null) !== 'valid_one_year_no_n2_aging') {
                    return 0;
                }

                $allocations = $metadata['protected_allocations'] ?? [];

                return collect(['n2', 'n1', 'current'])
                    ->sum(fn (string $bucket): int => max(0, (int) ($allocations[$bucket] ?? 0)));
            });

        return min(max(0, (int) $granted), max(0, $liveN1));
    }

    /**
     * @param  array{n2:int, n1:int, current:int}  $remaining
     * @param  array{n2:int, n1:int, current:int}  $protected
     */
    private function assertProtectedAllocationsRemain(int $year, array $remaining, array $protected): void
    {
        foreach (['n2', 'n1', 'current'] as $bucket) {
            if ($protected[$bucket] > $remaining[$bucket]) {
                throw ValidationException::withMessages([
                    'saldo' => "Replay mengambil hak terlindungi pada bucket {$bucket} tahun {$year}.",
                ]);
            }
        }
    }

    private function hasApprovedCutiBesar(string $employeeId, int $year): bool
    {
        return LeaveUsageRecord::query()
            ->where('employee_id', $employeeId)
            ->where('usage_year', $year)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->where('workdays', '>', 0)
            ->whereHas('jenisCuti', fn ($query) => $query->where('code', 'besar'))
            ->exists();
    }

    /** @return array<string, int> */
    private function balanceSnapshot(LeaveBalance $balance): array
    {
        return [
            'tahun' => $balance->tahun,
            'jatah_awal' => $balance->jatah_awal,
            'carry_over' => $balance->carry_over,
            'terpakai' => $balance->terpakai,
            'sisa' => $balance->sisa,
            'sisa_n2' => $balance->sisa_n2,
            'sisa_n1' => $balance->sisa_n1,
            'sisa_tahun_berjalan' => $balance->sisa_tahun_berjalan,
            'terpakai_tahun_berjalan' => $balance->terpakai_tahun_berjalan,
            'hangus' => $balance->hangus,
        ];
    }
}
