<?php

namespace App\Services\Cuti;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service tulis saldo cuti tahunan berbasis ledger.
 *
 * Ledger menjadi buku besar audit, sedangkan leave_balances adalah ringkasan cepat untuk UI/API.
 * Semua perubahan saldo wajib menulis ledger dan memperbarui summary dalam transaksi yang sama.
 */
class LeaveBalanceService
{
    public function __construct(private readonly LeaveBalanceCalculator $calculator) {}

    /**
     * Mengecek saldo tersedia tanpa mengunci baris dan tanpa reservasi.
     * Dipakai saat submit/resubmit agar validasi awal konsisten dengan mesin deduction final.
     */
    public function availableFor(Employee|string $employee, int $tahun, ?Carbon $asOf = null): int
    {
        $employeeModel = $this->resolveEmployee($employee);
        $balance = $this->ensureAnnualEntitlement($employeeModel, $tahun, $asOf ?? Carbon::create($tahun, 1, 1));

        if ($balance === null) {
            return 0;
        }

        return $this->calculator->availableTotal($this->bucketsFromBalance($balance));
    }

    /**
     * Memotong saldo tahunan saat approval final.
     *
     * Aturan penting: method ini menggantikan path lama yang langsung mengurangi `sisa`.
     * Jangan panggil bersama pemotongan summary lama, karena itu akan membuat saldo terdebit dua kali.
     */
    public function deductForFinalApproval(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->jenisCuti?->code === 'besar') {
            $this->assertCutiBesarCanBeFinallyApproved($leaveRequest->employee_id, $leaveRequest->tanggal_mulai->year);

            return;
        }

        if (! $leaveRequest->jenisCuti?->mengurangi_saldo_tahunan) {
            return;
        }

        if ($this->alreadyDeducted($leaveRequest)) {
            return;
        }

        $tahun = $leaveRequest->tanggal_mulai->year;
        $requested = (int) $leaveRequest->jumlah_hari_kerja;

        $employee = $this->resolveEmployee($leaveRequest->employee_id);
        $this->ensureAnnualEntitlement($employee, $tahun, $leaveRequest->tanggal_mulai);

        // Method ini bisa dipanggil langsung (bukan hanya dari LeaveApprovalService::approve),
        // sehingga kepemilikan transaksi ada di sini: kunci baris, tulis ledger per bucket,
        // sinkronisasi ringkasan, dan audit pemotongan harus commit atau rollback bersama.
        DB::transaction(function () use ($leaveRequest, $tahun, $requested): void {
            $balance = LeaveBalance::query()
                ->where('employee_id', $leaveRequest->employee_id)
                ->where('tahun', $tahun)
                ->lockForUpdate()
                ->first();

            // Cek ulang di dalam kunci agar dua approval final paralel tidak memotong saldo dua kali.
            if ($this->alreadyDeducted($leaveRequest)) {
                return;
            }

            $buckets = $balance === null
                ? ['n2' => 0, 'n1' => 0, 'current' => 0]
                : $this->bucketsFromBalance($balance);
            $allocation = $this->calculator->allocateDeduction($buckets, $requested);

            if (! $allocation['success'] || $balance === null) {
                $available = $this->calculator->availableTotal($buckets);

                throw ValidationException::withMessages([
                    'status' => "Saldo cuti tahunan tidak mencukupi saat persetujuan final. Sisa {$available} hari, dibutuhkan {$requested} hari.",
                ]);
            }

            $oldBalance = $this->calculator->availableTotal($buckets);
            $newBalance = $this->calculator->availableTotal($allocation['remaining']);

            $this->writeDeductionLedger($leaveRequest, $balance, $allocation['allocations']);
            $this->updateSummaryAfterDeduction($balance, $allocation['remaining'], $allocation['allocations'], $requested);

            // Audit pemotongan ditulis fail-closed di dalam transaksi yang sama dengan ledger/ringkasan,
            // sehingga kegagalan audit membatalkan seluruh mutasi dan retry idempoten tidak menghasilkan audit ganda.
            $this->auditDeductionOrFail(
                leaveRequest: $leaveRequest,
                balance: $balance,
                sourceYear: $tahun,
                requested: $requested,
                oldBalance: $oldBalance,
                newBalance: $newBalance,
                allocations: $allocation['allocations'],
            );
        });
    }

    /**
     * Menutup saldo tahun sumber dan membuat ringkasan tahun target secara idempotent.
     * `{year}` pada command adalah tahun sumber yang ditutup, sehingga target selalu `{year + 1}`.
     */
    public function rolloverYear(int $sourceYear): void
    {
        $targetYear = $sourceYear + 1;

        LeaveBalance::query()
            ->where('tahun', $sourceYear)
            ->orderBy('employee_id')
            ->each(function (LeaveBalance $sourceBalance) use ($sourceYear, $targetYear): void {
                $dedupKey = "{$sourceBalance->employee_id}:{$targetYear}:rollover_applied";

                if (LeaveBalanceLedger::query()->where('dedup_key', $dedupKey)->exists()) {
                    return;
                }

                DB::transaction(function () use ($sourceBalance, $sourceYear, $targetYear, $dedupKey): void {
                    $lockedSource = LeaveBalance::query()->whereKey($sourceBalance->id)->lockForUpdate()->firstOrFail();
                    $buckets = $this->bucketsFromBalance($lockedSource);
                    $postponedByDuty = $this->postponedByDuty($lockedSource->employee_id, $sourceYear);
                    $expiredDutyCarryOver = $this->dutyCarryOverExpiringIn($lockedSource->employee_id, $sourceYear);
                    $result = $this->calculator->calculateRollover(
                        previousN1: max(0, $buckets['n1'] - $expiredDutyCarryOver),
                        previousCurrent: $buckets['current'],
                        twoYearsNoAnnualLeave: $this->hasNoAnnualLeaveForTwoYears($lockedSource->employee_id, $sourceYear),
                        postponedByDuty: $postponedByDuty,
                    );
                    // Carry-over dari penangguhan dinas hanya hidup satu tahun dan tidak boleh naik menjadi bucket N-2.
                    $result['hangus'] += $expiredDutyCarryOver;

                    $targetBalance = LeaveBalance::query()->firstOrCreate(
                        ['employee_id' => $lockedSource->employee_id, 'tahun' => $targetYear],
                        [
                            'jatah_awal' => $this->calculator->annualEntitlement(),
                            'carry_over' => $result['n2'] + $result['n1'],
                            'terpakai' => 0,
                            'sisa' => $result['maxUsable'],
                            'sisa_n2' => $result['n2'],
                            'sisa_n1' => $result['n1'],
                            'sisa_tahun_berjalan' => $result['current'],
                            'terpakai_tahun_berjalan' => 0,
                            'hangus' => $result['hangus'],
                        ],
                    );
                    $targetCurrent = max(0, $this->calculator->annualEntitlement() - $targetBalance->terpakai_tahun_berjalan);
                    $targetAvailable = $result['n2'] + $result['n1'] + $targetCurrent;

                    // Manual rerun bisa terjadi setelah jatah target lebih dulu dibuat; summary target tetap harus sinkron dengan ledger rollover.
                    // Jika target tahun sudah terpakai sebelum rollover terlambat dijalankan, pemakaian itu tidak boleh direfund.
                    $targetBalance->forceFill([
                        'jatah_awal' => $this->calculator->annualEntitlement(),
                        'carry_over' => $result['n2'] + $result['n1'],
                        'sisa' => $targetAvailable,
                        'sisa_n2' => $result['n2'],
                        'sisa_n1' => $result['n1'],
                        'sisa_tahun_berjalan' => $targetCurrent,
                        'hangus' => $result['hangus'],
                    ])->save();

                    $this->writeRolloverLedger($lockedSource, $targetBalance, $sourceYear, $targetYear, $result, $dedupKey);
                });
            });
    }

    /**
     * Mencatat saldo awal tahunan sebagai event khusus, bukan koreksi generik.
     * Dedup per pegawai+tahun mencegah input saldo awal tertulis dua kali saat admin mengulang submit.
     *
     * @param  array{n2:int, n1:int, current:int}  $buckets
     */
    public function setOpeningBalance(Employee|string $employee, int $tahun, array $buckets, string $reason, User $actor): LeaveBalance
    {
        $employeeModel = $this->resolveEmployee($employee);
        $dedupKey = "{$employeeModel->id}:{$tahun}:opening_balance_set";

        return DB::transaction(function () use ($employeeModel, $tahun, $buckets, $reason, $actor, $dedupKey): LeaveBalance {
            $existingLedger = LeaveBalanceLedger::query()
                ->where('dedup_key', $dedupKey)
                ->first();

            $balance = LeaveBalance::query()->firstOrCreate(
                ['employee_id' => $employeeModel->id, 'tahun' => $tahun],
                ['jatah_awal' => 0, 'carry_over' => 0, 'terpakai' => 0, 'sisa' => 0]
            );
            if ($this->hasBalanceDeduction($employeeModel->id, $tahun)) {
                throw ValidationException::withMessages([
                    'saldo' => 'Saldo awal tidak dapat diedit setelah ada pemotongan cuti. Gunakan koreksi manual.',
                ]);
            }

            $oldTotal = (int) $balance->sisa;
            $normalized = $this->normalizeBuckets($buckets);
            $newTotal = $this->calculator->availableTotal($normalized);

            $balance->forceFill([
                'jatah_awal' => $normalized['current'],
                'carry_over' => $normalized['n2'] + $normalized['n1'],
                'terpakai' => 0,
                'sisa' => $newTotal,
                'sisa_n2' => $normalized['n2'],
                'sisa_n1' => $normalized['n1'],
                'sisa_tahun_berjalan' => $normalized['current'],
                'terpakai_tahun_berjalan' => 0,
                'hangus' => 0,
            ])->save();

            $ledgerPayload = [
                'employee_id' => $employeeModel->id,
                'leave_balance_id' => $balance->id,
                'tahun' => $tahun,
                'event_type' => LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
                'amount' => $newTotal,
                'source_year' => $tahun,
                'reason' => $reason,
                'dedup_key' => $dedupKey,
                'metadata' => ['buckets' => $normalized],
                'created_by' => $actor->id,
                'occurred_at' => Carbon::now(),
            ];

            // Saldo awal dapat diperbaiki sebelum ada pemotongan karena belum ada fakta cuti yang dikonsumsi.
            // Setelah deduction terjadi, perubahan harus masuk sebagai `manual_adjustment` append-only.
            if ($existingLedger === null) {
                LeaveBalanceLedger::create($ledgerPayload);
            } else {
                $existingLedger->forceFill($ledgerPayload)->save();
            }

            $this->auditBalanceChange(
                event: 'LEAVE_BALANCE_OPENING_SET',
                balance: $balance,
                actor: $actor,
                reason: $reason,
                sourceYear: $tahun,
                oldBalance: $oldTotal,
                newBalance: $newTotal,
                delta: $newTotal - $oldTotal,
                extra: ['buckets' => $normalized],
            );

            return $balance;
        });
    }

    /**
     * Menulis koreksi saldo manual sebagai delta append-only.
     * Debit dikunci ke sisa bucket agar saldo tidak pernah menjadi negatif atau membentuk utang cuti.
     */
    public function adjustBalance(Employee|string $employee, int $tahun, string $bucket, int $amount, string $reason, User $actor): LeaveBalance
    {
        if ($amount === 0) {
            throw ValidationException::withMessages([
                'amount' => 'Jumlah koreksi saldo tidak boleh nol.',
            ]);
        }

        $employeeModel = $this->resolveEmployee($employee);

        return DB::transaction(function () use ($employeeModel, $tahun, $bucket, $amount, $reason, $actor): LeaveBalance {
            $balance = LeaveBalance::query()
                ->where('employee_id', $employeeModel->id)
                ->where('tahun', $tahun)
                ->lockForUpdate()
                ->firstOrCreate(
                    ['employee_id' => $employeeModel->id, 'tahun' => $tahun],
                    ['jatah_awal' => 0, 'carry_over' => 0, 'terpakai' => 0, 'sisa' => 0]
                );
            $buckets = $this->bucketsFromBalance($balance);
            $oldTotal = $this->calculator->availableTotal($buckets);
            $currentBucketValue = $this->bucketValue($buckets, $bucket);
            $applied = $amount < 0 ? -min(abs($amount), $currentBucketValue) : $amount;

            $remaining = $this->applyBucketDelta($buckets, $bucket, $applied);
            $newTotal = $this->calculator->availableTotal($remaining);
            $sourceYear = $this->sourceYearForBucket($tahun, $bucket);
            $metadata = [
                'bucket' => $bucket,
                'old_bucket_balance' => $currentBucketValue,
                'new_bucket_balance' => $this->bucketValue($remaining, $bucket),
            ];

            if ($amount < 0 && abs($amount) !== abs($applied)) {
                $metadata['niat_pengurangan'] = abs($amount);
                $metadata['diterapkan'] = abs($applied);
            }

            LeaveBalanceLedger::create([
                'employee_id' => $employeeModel->id,
                'leave_balance_id' => $balance->id,
                'tahun' => $tahun,
                'event_type' => LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT,
                'amount' => $applied,
                'source_year' => $sourceYear,
                'reason' => $reason,
                'metadata' => $metadata,
                'created_by' => $actor->id,
                'occurred_at' => Carbon::now(),
            ]);

            $this->fillSummaryBuckets($balance, $remaining)->save();

            $this->auditBalanceChange(
                event: 'LEAVE_BALANCE_CORRECTED',
                balance: $balance,
                actor: $actor,
                reason: $reason,
                sourceYear: $sourceYear,
                oldBalance: $oldTotal,
                newBalance: $newTotal,
                delta: $applied,
                extra: $metadata,
            );

            return $balance;
        });
    }

    /**
     * Mencatat sisa cuti yang secara formal ditunda karena tugas dinas.
     * Status workflow `Ditangguhkan` tidak cukup, karena status itu hanya jeda approval dan bukan hak saldo baru.
     */
    public function recordDutyPostponement(Employee|string $employee, int $sourceYear, int $days, string $reason, ?LeaveRequest $leaveRequest, ?User $actor = null): LeaveBalanceLedger
    {
        if ($days <= 0) {
            throw ValidationException::withMessages([
                'jumlah_hari' => 'Jumlah hari penangguhan dinas harus lebih dari nol.',
            ]);
        }

        $employeeModel = $this->resolveEmployee($employee);
        $balance = LeaveBalance::query()
            ->where('employee_id', $employeeModel->id)
            ->where('tahun', $sourceYear)
            ->first();
        $source = $leaveRequest?->id ?? 'annual_record';

        return LeaveBalanceLedger::query()->firstOrCreate(
            ['dedup_key' => "{$employeeModel->id}:{$sourceYear}:duty_postponement_recorded:{$source}"],
            [
                'employee_id' => $employeeModel->id,
                'leave_request_id' => $leaveRequest?->id,
                'leave_balance_id' => $balance?->id,
                'tahun' => $sourceYear,
                'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
                'amount' => 0,
                'source_year' => $sourceYear,
                'reason' => $reason,
                'created_by' => $actor?->id,
                'metadata' => [
                    'postponed_days' => $days,
                    'expiry_policy' => 'valid_one_year_no_n2_aging',
                ],
                'occurred_at' => Carbon::now(),
            ],
        );
    }

    /**
     * Cuti besar tidak boleh disetujui setelah saldo cuti tahunan tahun yang sama sudah dipakai.
     * Guard ini fail-closed agar entitlement tahunan tidak perlu ditulis ulang pada slice ini.
     */
    public function assertCutiBesarCanBeFinallyApproved(Employee|string $employee, int $year): void
    {
        $employeeModel = $this->resolveEmployee($employee);
        $alreadyDeducted = LeaveBalanceLedger::query()
            ->where('employee_id', $employeeModel->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED)
            ->where('tahun', $year)
            ->exists();

        if ($alreadyDeducted) {
            throw ValidationException::withMessages([
                'status' => 'Cuti besar tidak dapat disetujui karena cuti tahunan tahun yang sama sudah dipakai.',
            ]);
        }
    }

    /**
     * Mengubah summary lama menjadi bucket N-2/N-1/tahun berjalan.
     *
     * Baris lama hanya punya `carry_over` dan `sisa`, sehingga saat bucket baru masih kosong
     * service memetakan carry_over ke N-1 dan sisa sisanya ke tahun berjalan.
     * Setelah baris pernah disentuh service ledger, kolom bucket menjadi sumber ringkasan yang dipakai.
     *
     * @return array{n2:int, n1:int, current:int}
     */
    private function bucketsFromBalance(LeaveBalance $balance): array
    {
        $sisaN2 = (int) ($balance->sisa_n2 ?? 0);
        $sisaN1 = (int) ($balance->sisa_n1 ?? 0);
        $sisaCurrent = (int) ($balance->sisa_tahun_berjalan ?? 0);
        $bucketTotal = $sisaN2 + $sisaN1 + $sisaCurrent;

        if ($bucketTotal === 0 && $balance->sisa > 0) {
            $n1 = min($balance->carry_over, $balance->sisa);

            return [
                'n2' => 0,
                'n1' => $n1,
                'current' => $balance->sisa - $n1,
            ];
        }

        return [
            'n2' => $sisaN2,
            'n1' => $sisaN1,
            'current' => $sisaCurrent,
        ];
    }

    /**
     * @param  array{n2:int, n1:int, current:int}  $buckets
     * @return array{n2:int, n1:int, current:int}
     */
    private function normalizeBuckets(array $buckets): array
    {
        return [
            'n2' => max(0, (int) $buckets['n2']),
            'n1' => max(0, (int) $buckets['n1']),
            'current' => max(0, (int) $buckets['current']),
        ];
    }

    /**
     * @param  array{n2:int, n1:int, current:int}  $buckets
     */
    private function bucketValue(array $buckets, string $bucket): int
    {
        return match ($bucket) {
            'n2' => $buckets['n2'],
            'n1' => $buckets['n1'],
            'current' => $buckets['current'],
            default => throw ValidationException::withMessages([
                'bucket' => 'Bucket saldo cuti tidak valid.',
            ]),
        };
    }

    /**
     * @param  array{n2:int, n1:int, current:int}  $buckets
     * @return array{n2:int, n1:int, current:int}
     */
    private function applyBucketDelta(array $buckets, string $bucket, int $amount): array
    {
        $remaining = $buckets;
        $remaining[$bucket] = max(0, $remaining[$bucket] + $amount);

        return $remaining;
    }

    private function sourceYearForBucket(int $tahun, string $bucket): int
    {
        return match ($bucket) {
            'n2' => $tahun - 2,
            'n1' => $tahun - 1,
            'current' => $tahun,
            default => throw ValidationException::withMessages([
                'bucket' => 'Bucket saldo cuti tidak valid.',
            ]),
        };
    }

    /**
     * Ringkasan materialized selalu disinkronkan dari bucket agar UI cepat tanpa menghitung ulang ledger.
     *
     * @param  array{n2:int, n1:int, current:int}  $buckets
     */
    private function fillSummaryBuckets(LeaveBalance $balance, array $buckets): LeaveBalance
    {
        $available = $this->calculator->availableTotal($buckets);

        return $balance->forceFill([
            'carry_over' => $buckets['n2'] + $buckets['n1'],
            'sisa' => $available,
            'sisa_n2' => $buckets['n2'],
            'sisa_n1' => $buckets['n1'],
            'sisa_tahun_berjalan' => $buckets['current'],
        ]);
    }

    /**
     * Audit log melengkapi ledger: ledger untuk rekonstruksi saldo, audit untuk jejak aktor dan konteks admin.
     *
     * @param  array<string, mixed>  $extra
     */
    private function auditBalanceChange(string $event, LeaveBalance $balance, ?User $actor, string $reason, int $sourceYear, int $oldBalance, int $newBalance, int $delta, array $extra = []): void
    {
        $payload = array_merge([
            'employee_id' => $balance->employee_id,
            'tahun' => $balance->tahun,
            'tahun_sumber' => $sourceYear,
            'reason' => $reason,
            'corrected_by' => $actor?->id,
            'corrected_at' => Carbon::now()->toIso8601String(),
        ], $extra);

        $oldValues = array_merge($payload, ['old_balance' => $oldBalance]);
        $newValues = array_merge($payload, ['new_balance' => $newBalance, 'delta' => $delta]);

        if ($actor === null) {
            AuditService::log($event, 'LeaveBalance', $balance->id, $oldValues, $newValues);

            return;
        }

        AuditService::logAs((string) $actor->id, (string) $actor->name, $event, 'LeaveBalance', $balance->id, $oldValues, $newValues);
    }

    /**
     * Menulis audit pemotongan saldo secara fail-closed di dalam transaksi deduction.
     *
     * Berbeda dengan AuditService generik yang sengaja fire-and-forget (kegagalan audit tidak boleh
     * menggagalkan operasi utama seperti login/CRUD), audit pemotongan saldo adalah bagian tak terpisahkan
     * dari mutasi saldo: jika audit gagal ditulis, ledger dan ringkasan tidak boleh ikut commit.
     * Karena itu insert dilakukan langsung tanpa try/catch agar exception membubung dan me-rollback transaksi.
     *
     * @param  array{n2:int, n1:int, current:int}  $allocations
     */
    private function auditDeductionOrFail(LeaveRequest $leaveRequest, LeaveBalance $balance, int $sourceYear, int $requested, int $oldBalance, int $newBalance, array $allocations): void
    {
        // Panggilan langsung service (di luar HTTP) sah bila tidak ada user terautentikasi, sehingga aktor bisa null.
        $actor = Auth::user();

        $payload = [
            'employee_id' => $balance->employee_id,
            'tahun' => $balance->tahun,
            'tahun_sumber' => $sourceYear,
            'reason' => 'Pemotongan saldo cuti tahunan pada persetujuan final.',
            'corrected_by' => $actor?->id,
            'corrected_at' => Carbon::now()->toIso8601String(),
            'leave_request_id' => $leaveRequest->id,
            'requested_days' => $requested,
            'allocations' => $allocations,
        ];

        AuditLog::create([
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'event' => 'LEAVE_BALANCE_DEDUCTED',
            'auditable_type' => 'LeaveBalance',
            'auditable_id' => $balance->id,
            'old_values' => array_merge($payload, ['old_balance' => $oldBalance]),
            'new_values' => array_merge($payload, ['new_balance' => $newBalance, 'delta' => $newBalance - $oldBalance]),
        ]);
    }

    private function alreadyDeducted(LeaveRequest $leaveRequest): bool
    {
        return LeaveBalanceLedger::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED)
            ->exists();
    }

    private function hasBalanceDeduction(string $employeeId, int $tahun): bool
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('tahun', $tahun)
            ->where('event_type', LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED)
            ->exists();
    }

    private function hasNoAnnualLeaveForTwoYears(string $employeeId, int $sourceYear): bool
    {
        return ! $this->approvedAnnualLeaveQuery($employeeId)
            ->where(function (Builder $query) use ($sourceYear): void {
                $query->whereYear('tanggal_mulai', $sourceYear - 1)
                    ->orWhereYear('tanggal_mulai', $sourceYear);
            })
            ->exists();
    }

    private function hasApprovedCutiBesar(string $employeeId, int $year): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'disetujui')
            ->whereYear('tanggal_mulai', $year)
            ->whereHas('jenisCuti', fn (Builder $query) => $query->where('code', 'besar'))
            ->exists();
    }

    private function approvedAnnualLeaveQuery(string $employeeId): Builder
    {
        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'disetujui')
            ->whereHas('jenisCuti', fn (Builder $query) => $query->where('code', 'tahunan'));
    }

    private function postponedByDuty(string $employeeId, int $sourceYear): int
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->where('source_year', $sourceYear)
            ->get(['metadata'])
            ->sum(fn (LeaveBalanceLedger $ledger): int => (int) ($ledger->metadata['postponed_days'] ?? 0));
    }

    private function dutyCarryOverExpiringIn(string $employeeId, int $sourceYear): int
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('tahun', $sourceYear)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED)
            ->get(['metadata'])
            ->sum(fn (LeaveBalanceLedger $ledger): int => (int) ($ledger->metadata['duty_postponed_carried'] ?? 0));
    }

    private function resolveEmployee(Employee|string $employee): Employee
    {
        if ($employee instanceof Employee) {
            return $employee->loadMissing('appointment');
        }

        return Employee::query()->with('appointment')->findOrFail($employee);
    }

    /**
     * Membuat jatah tahunan pertama saat saldo pertama kali dibutuhkan.
     *
     * Hak muncul penuh 12 hari setelah masa kerja minimal 1 tahun. Tidak ada proporsi bulanan,
     * sehingga pegawai yang baru eligible di tengah tahun tetap mendapat 12 hari untuk tahun itu.
     */
    private function ensureAnnualEntitlement(Employee $employee, int $tahun, Carbon $asOf): ?LeaveBalance
    {
        $existing = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', $tahun)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($this->hasApprovedCutiBesar($employee->id, $tahun)) {
            return null;
        }

        $tmt = $employee->appointment?->tmt_pengangkatan;

        if ($tmt === null || $tmt->copy()->addYear()->startOfDay()->greaterThan($asOf->copy()->startOfDay())) {
            return null;
        }

        return DB::transaction(function () use ($employee, $tahun): LeaveBalance {
            $balance = LeaveBalance::query()->firstOrCreate(
                ['employee_id' => $employee->id, 'tahun' => $tahun],
                [
                    'jatah_awal' => $this->calculator->annualEntitlement(),
                    'carry_over' => 0,
                    'terpakai' => 0,
                    'sisa' => $this->calculator->annualEntitlement(),
                    'sisa_n2' => 0,
                    'sisa_n1' => 0,
                    'sisa_tahun_berjalan' => $this->calculator->annualEntitlement(),
                    'terpakai_tahun_berjalan' => 0,
                    'hangus' => 0,
                ],
            );

            LeaveBalanceLedger::query()->firstOrCreate(
                ['dedup_key' => "{$employee->id}:{$tahun}:annual_entitlement_granted"],
                [
                    'employee_id' => $employee->id,
                    'leave_balance_id' => $balance->id,
                    'tahun' => $tahun,
                    'event_type' => LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
                    'amount' => $this->calculator->annualEntitlement(),
                    'source_year' => $tahun,
                    'reason' => 'Jatah cuti tahunan pertama dibuat saat saldo pertama kali dibutuhkan.',
                    'metadata' => ['grant_policy' => 'full_12_days_no_proration'],
                    'occurred_at' => Carbon::now(),
                ],
            );

            return $balance;
        });
    }

    /**
     * Menulis satu ledger per bucket sumber agar audit bisa menjawab saldo dipotong dari tahun mana.
     * `source_year` adalah nama kolom database untuk istilah domain `tahun_sumber`.
     *
     * @param  array{n2:int, n1:int, current:int}  $allocations
     */
    private function writeDeductionLedger(LeaveRequest $leaveRequest, LeaveBalance $balance, array $allocations): void
    {
        $tahun = $leaveRequest->tanggal_mulai->year;
        $sourceYears = [
            'n2' => $tahun - 2,
            'n1' => $tahun - 1,
            'current' => $tahun,
        ];

        foreach ($allocations as $bucket => $amount) {
            if ($amount <= 0) {
                continue;
            }

            LeaveBalanceLedger::create([
                'employee_id' => $leaveRequest->employee_id,
                'leave_request_id' => $leaveRequest->id,
                'leave_balance_id' => $balance->id,
                'tahun' => $tahun,
                'event_type' => LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED,
                'amount' => -$amount,
                'source_year' => $sourceYears[$bucket],
                'reason' => 'Pemotongan saldo cuti tahunan pada persetujuan final.',
                'dedup_key' => "leave_deducted:{$leaveRequest->id}:{$sourceYears[$bucket]}",
                'metadata' => [
                    'bucket' => $bucket,
                    'requested_days' => (int) $leaveRequest->jumlah_hari_kerja,
                ],
                'occurred_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * Menulis ledger rollover terpisah untuk audit: marker idempotensi, carry-over masuk, dan saldo hangus.
     * `source_year` menjelaskan asal saldo karena target row selalu berada di tahun baru.
     *
     * @param  array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}  $result
     */
    private function writeRolloverLedger(LeaveBalance $sourceBalance, LeaveBalance $targetBalance, int $sourceYear, int $targetYear, array $result, string $rolloverKey): void
    {
        LeaveBalanceLedger::query()->firstOrCreate(
            ['dedup_key' => $rolloverKey],
            [
                'employee_id' => $sourceBalance->employee_id,
                'leave_balance_id' => $targetBalance->id,
                'tahun' => $targetYear,
                'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
                'amount' => 0,
                'source_year' => $sourceYear,
                'reason' => 'Rollover saldo cuti tahunan ke tahun berikutnya.',
                'metadata' => [
                    'source_balance_id' => $sourceBalance->id,
                    'target_year' => $targetYear,
                ],
                'occurred_at' => Carbon::now(),
            ],
        );

        LeaveBalanceLedger::query()->firstOrCreate(
            ['dedup_key' => "{$sourceBalance->employee_id}:{$targetYear}:annual_entitlement_granted"],
            [
                'employee_id' => $sourceBalance->employee_id,
                'leave_balance_id' => $targetBalance->id,
                'tahun' => $targetYear,
                'event_type' => LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
                'amount' => $this->calculator->annualEntitlement(),
                'source_year' => $targetYear,
                'reason' => 'Jatah cuti tahunan tahun target dibuat saat rollover.',
                'metadata' => ['grant_policy' => 'full_12_days_no_proration'],
                'occurred_at' => Carbon::now(),
            ],
        );

        $carryOver = $result['n2'] + $result['n1'];

        if ($carryOver > 0) {
            LeaveBalanceLedger::query()->firstOrCreate(
                ['dedup_key' => "{$sourceBalance->employee_id}:{$targetYear}:carry_over_granted:{$sourceYear}"],
                [
                    'employee_id' => $sourceBalance->employee_id,
                    'leave_balance_id' => $targetBalance->id,
                    'tahun' => $targetYear,
                    'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
                    'amount' => $carryOver,
                    'source_year' => $sourceYear,
                    'reason' => 'Sisa cuti yang memenuhi syarat dibawa ke tahun berikutnya.',
                    'metadata' => [
                        'n2' => $result['n2'],
                        'n1' => $result['n1'],
                        'duty_postponed_carried' => $this->dutyPostponedCarried($sourceBalance, $sourceYear, $result),
                    ],
                    'occurred_at' => Carbon::now(),
                ],
            );
        }

        if ($result['hangus'] > 0) {
            LeaveBalanceLedger::query()->firstOrCreate(
                ['dedup_key' => "{$sourceBalance->employee_id}:{$targetYear}:carry_over_expired:{$sourceYear}"],
                [
                    'employee_id' => $sourceBalance->employee_id,
                    'leave_balance_id' => $targetBalance->id,
                    'tahun' => $targetYear,
                    'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_EXPIRED,
                    'amount' => 0,
                    'source_year' => $sourceYear,
                    'reason' => 'Sisa cuti melewati batas carry-over dan hangus saat rollover.',
                    'metadata' => [
                        'expired_days' => $result['hangus'],
                        'target_year' => $targetYear,
                    ],
                    'occurred_at' => Carbon::now(),
                ],
            );
        }

        $oldBalance = $this->calculator->availableTotal($this->bucketsFromBalance($sourceBalance));
        $newBalance = $this->calculator->availableTotal($this->bucketsFromBalance($targetBalance));
        $this->auditBalanceChange(
            event: 'LEAVE_ROLLOVER_APPLIED',
            balance: $targetBalance,
            actor: null,
            reason: 'Rollover saldo cuti tahunan ke tahun berikutnya.',
            sourceYear: $sourceYear,
            oldBalance: $oldBalance,
            newBalance: $newBalance,
            delta: $newBalance - $oldBalance,
            extra: [
                'carry_over' => $result['n2'] + $result['n1'],
                'hangus' => $result['hangus'],
            ],
        );
    }

    /**
     * Menghitung porsi N-1 target yang berasal dari penangguhan dinas.
     * Porsi ini diberi metadata khusus agar rollover berikutnya bisa menghanguskannya, bukan mengubahnya jadi N-2.
     *
     * @param  array{n2:int, n1:int, current:int, hangus:int, maxUsable:int}  $result
     */
    private function dutyPostponedCarried(LeaveBalance $sourceBalance, int $sourceYear, array $result): int
    {
        $buckets = $this->bucketsFromBalance($sourceBalance);
        $normal = $this->calculator->calculateRollover(
            previousN1: $buckets['n1'],
            previousCurrent: $buckets['current'],
            twoYearsNoAnnualLeave: $this->hasNoAnnualLeaveForTwoYears($sourceBalance->employee_id, $sourceYear),
            postponedByDuty: 0,
        );

        return max(0, ($result['n2'] + $result['n1']) - ($normal['n2'] + $normal['n1']));
    }

    /**
     * Memperbarui summary materialized dari hasil alokasi ledger.
     * `terpakai` adalah total cuti yang dipakai, sedangkan `terpakai_tahun_berjalan`
     * hanya mencatat porsi yang benar-benar mengambil bucket jatah tahun berjalan.
     *
     * @param  array{n2:int, n1:int, current:int}  $remaining
     * @param  array{n2:int, n1:int, current:int}  $allocations
     */
    private function updateSummaryAfterDeduction(LeaveBalance $balance, array $remaining, array $allocations, int $requested): void
    {
        $available = $this->calculator->availableTotal($remaining);

        $balance->forceFill([
            'sisa_n2' => $remaining['n2'],
            'sisa_n1' => $remaining['n1'],
            'sisa_tahun_berjalan' => $remaining['current'],
            'terpakai' => $balance->terpakai + $requested,
            'terpakai_tahun_berjalan' => $balance->terpakai_tahun_berjalan + $allocations['current'],
            'sisa' => $available,
            'carry_over' => $remaining['n2'] + $remaining['n1'],
        ])->save();
    }
}
