<?php

namespace App\Services\Cuti;

use App\Actions\Cuti\RolloverLeaveBalanceAction;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
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
    public function __construct(
        private readonly LeaveBalanceCalculator $calculator,
        private readonly RolloverLeaveBalanceAction $rollover,
    ) {}

    /**
     * Mengecek saldo tersedia tanpa mengunci baris dan tanpa reservasi.
     * Dipakai saat submit/resubmit agar validasi awal konsisten dengan mesin deduction final.
     */
    public function availableFor(Employee|string $employee, int $tahun, ?Carbon $asOf = null): int
    {
        $employeeModel = $this->resolveEmployee($employee);

        if ($this->hasApprovedCutiBesar($employeeModel->id, $tahun)) {
            return 0;
        }

        $balance = $this->ensureAnnualEntitlement($employeeModel, $tahun, $asOf ?? Carbon::create($tahun, 1, 1));

        if ($balance === null) {
            return 0;
        }

        $protected = $this->protectedAllocations($employeeModel->id, $tahun);
        $available = $this->subtractProtectedBuckets($this->bucketsFromBalance($balance), $protected);

        return $this->calculator->availableTotal($available);
    }

    /**
     * Menyusun preview saldo tanpa menulis entitlement atau ledger dari endpoint GET.
     *
     * Kontrak preview sengaja menggunakan mesin bucket dan eligibility yang sama dengan
     * `availableFor()`. Bedanya, preview hanya menampilkan entitlement virtual bila baris
     * saldo belum pernah dibuat; penulisan entitlement tetap terjadi pada alur mutasi
     * yang sah (submit/final approval). Dengan demikian refresh form Pegawai tidak
     * memiliki efek samping data.
     *
     * Alokasi aktif dibaca dari event reservasi yang append-only. Event tersebut
     * tidak mengubah saldo final; ia hanya mengurangi hak yang masih dapat diajukan.
     *
     * @return array{
     *     tahun:int,
     *     tanggal_acuan:string,
     *     eligible:bool,
     *     jatah_dasar:int,
     *     carry_over:int,
     *     terpakai_final:int,
     *     koreksi_administratif:int,
     *     saldo_aktual:int,
     *     dialokasikan_aktif:int,
     *     dilindungi_penangguhan_dinas:int,
     *     saldo_dapat_diajukan:int,
     *     rule_5_active:bool,
     *     bucket:array{n2:int,n1:int,current:int}
     * }
     */
    public function previewFor(
        Employee|string $employee,
        Carbon $asOf,
        ?LeaveRequest $excludingLeaveRequest = null,
    ): array {
        $employeeModel = $this->resolveEmployee($employee);
        $tahun = $asOf->year;
        $balance = LeaveBalance::query()
            ->where('employee_id', $employeeModel->id)
            ->where('tahun', $tahun)
            ->first();
        $rule5Active = $this->hasApprovedCutiBesar($employeeModel->id, $tahun);

        $usedN1 = (int) (LeaveBalance::query()
            ->where('employee_id', $employeeModel->id)
            ->where('tahun', $tahun - 1)
            ->value('terpakai') ?? 0);
        $usedN2 = (int) (LeaveBalance::query()
            ->where('employee_id', $employeeModel->id)
            ->where('tahun', $tahun - 2)
            ->value('terpakai') ?? 0);

        if ($rule5Active) {
            // Cuti Besar final menonaktifkan hak efektif tanpa mengubah summary atau ledger,
            // karena keduanya tetap diperlukan sebagai riwayat administratif yang auditabel.
            return [
                'tahun' => $tahun,
                'tanggal_acuan' => $asOf->toDateString(),
                'eligible' => false,
                'jatah_dasar' => 0,
                'carry_over' => 0,
                'terpakai_final' => (int) ($balance?->terpakai ?? 0),
                'used_n1' => $usedN1,
                'used_n2' => $usedN2,
                'koreksi_administratif' => (int) LeaveBalanceLedger::query()
                    ->where('employee_id', $employeeModel->id)
                    ->where('tahun', $tahun)
                    ->where('event_type', LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT)
                    ->sum('amount'),
                'saldo_aktual' => 0,
                'dialokasikan_aktif' => 0,
                'dilindungi_penangguhan_dinas' => 0,
                'saldo_dapat_diajukan' => 0,
                'rule_5_active' => true,
                'bucket' => ['n2' => 0, 'n1' => 0, 'current' => 0],
            ];
        }

        $eligible = $balance !== null || $this->isEligibleForAnnualEntitlement($employeeModel, $asOf);
        $buckets = $balance === null
            ? ($eligible
                ? ['n2' => 0, 'n1' => 0, 'current' => $this->calculator->annualEntitlement()]
                : ['n2' => 0, 'n1' => 0, 'current' => 0])
            : $this->bucketsFromBalance($balance);
        $saldoAktual = $this->calculator->availableTotal($buckets);
        $activeReservations = LeaveBalanceReservationEvent::query()
            ->forActiveRequests()
            ->where('employee_id', $employeeModel->id)
            ->where('tahun', $tahun);

        // Panel verifikator menilai kelayakan pengajuan yang sudah mereservasi saldo.
        // Reservasi pengajuan itu sendiri dikecualikan agar tidak mengurangi haknya dua kali,
        // sedangkan reservasi pengajuan aktif lain tetap mengurangi saldo yang tersedia.
        if ($excludingLeaveRequest !== null && $excludingLeaveRequest->employee_id === $employeeModel->id) {
            $activeReservations->where('leave_request_id', '!=', $excludingLeaveRequest->id);
        }

        $dialokasikanAktif = max(0, (int) $activeReservations->sum('amount'));
        $dilindungiPenangguhanDinas = $this->calculator->availableTotal(
            $this->protectedAllocations($employeeModel->id, $tahun),
        );

        return [
            'tahun' => $tahun,
            'tanggal_acuan' => $asOf->toDateString(),
            'eligible' => $eligible,
            'jatah_dasar' => $balance === null
                ? ($eligible ? $this->calculator->annualEntitlement() : 0)
                : (int) $balance->jatah_awal,
            'carry_over' => $buckets['n2'] + $buckets['n1'],
            'terpakai_final' => (int) ($balance?->terpakai ?? 0),
            'used_n1' => $usedN1,
            'used_n2' => $usedN2,
            'koreksi_administratif' => (int) LeaveBalanceLedger::query()
                ->where('employee_id', $employeeModel->id)
                ->where('tahun', $tahun)
                ->where('event_type', LeaveBalanceLedger::EVENT_MANUAL_ADJUSTMENT)
                ->sum('amount'),
            'saldo_aktual' => $saldoAktual,
            'dialokasikan_aktif' => $dialokasikanAktif,
            'dilindungi_penangguhan_dinas' => $dilindungiPenangguhanDinas,
            'saldo_dapat_diajukan' => max(0, $saldoAktual - $dialokasikanAktif - $dilindungiPenangguhanDinas),
            'rule_5_active' => false,
            'bucket' => $buckets,
        ];
    }

    /**
     * Memotong saldo tahunan saat approval final.
     *
     * Aturan penting: method ini menggantikan path lama yang langsung mengurangi `sisa`.
     * Jangan panggil bersama pemotongan summary lama, karena itu akan membuat saldo terdebit dua kali.
     */
    public function deductForFinalApproval(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing('jenisCuti');
        $tahun = $leaveRequest->tanggal_mulai->year;

        // Method ini bisa dipanggil langsung (bukan hanya dari LeaveApprovalService::approve),
        // sehingga kepemilikan transaksi ada di sini: kunci baris, tulis ledger per bucket,
        // sinkronisasi ringkasan, dan audit pemotongan harus commit atau rollback bersama.
        DB::transaction(function () use ($leaveRequest, $tahun): void {
            // Pegawai dikunci lebih dulu agar guard Rule 5 dan mutasi saldo memakai mutex yang sama.
            $employee = $this->lockEmployee($leaveRequest->employee_id);

            if ($leaveRequest->jenisCuti?->code === 'besar') {
                $this->assertCutiBesarCanBeFinallyApproved($employee, $tahun);

                return;
            }

            if (! $leaveRequest->jenisCuti?->mengurangi_saldo_tahunan) {
                return;
            }

            $this->assertAnnualLeaveAllowed($employee, $tahun);
            $this->assertSourceYearNotRolledOver($employee->id, $tahun);

            if ($this->alreadyDeducted($leaveRequest)) {
                return;
            }

            $this->ensureAnnualEntitlement($employee, $tahun, $leaveRequest->tanggal_mulai);
            $balance = LeaveBalance::query()
                ->where('employee_id', $employee->id)
                ->where('tahun', $tahun)
                ->lockForUpdate()
                ->first();

            // Cek ulang di dalam kunci agar dua approval final paralel tidak memotong saldo dua kali.
            if ($this->alreadyDeducted($leaveRequest)) {
                return;
            }

            $this->deductLockedBalance($leaveRequest, $balance, $tahun);
        });
    }

    /**
     * Menjalankan pemotongan setelah pegawai dan saldo tahun penggunaan terkunci.
     */
    private function deductLockedBalance(LeaveRequest $leaveRequest, ?LeaveBalance $balance, int $tahun): void
    {
        $requested = (int) $leaveRequest->jumlah_hari_kerja;
        $buckets = $balance === null
            ? ['n2' => 0, 'n1' => 0, 'current' => 0]
            : $this->bucketsFromBalance($balance);
        // Hari yang dilindungi penangguhan dinas tetap tersimpan di summary, tetapi tidak boleh
        // ikut dialokasikan. Perlindungan memakai urutan termuda-dahulu sedangkan pemotongan
        // memakai urutan tertua-dahulu, sehingga tanpa penyisihan ini pemotongan dapat mengambil
        // bucket lama yang sudah dilindungi dan membuat summary tidak konsisten dengan ledger.
        $protected = $balance === null
            ? ['n2' => 0, 'n1' => 0, 'current' => 0]
            : $this->protectedAllocations($leaveRequest->employee_id, $tahun);
        $unprotected = $this->subtractProtectedBuckets($buckets, $protected);
        $allocation = $this->calculator->allocateDeduction($unprotected, $requested);

        if (! $allocation['success'] || $balance === null) {
            $available = $this->calculator->availableTotal($unprotected);

            throw ValidationException::withMessages([
                'status' => "Saldo cuti tahunan tidak mencukupi saat persetujuan final. Sisa {$available} hari, dibutuhkan {$requested} hari.",
            ]);
        }

        // Hari terlindungi digabungkan kembali agar summary tetap mencerminkan seluruh hak tersimpan.
        $remaining = $this->mergeProtectedBuckets($allocation['remaining'], $protected);
        $oldBalance = $this->calculator->availableTotal($buckets);
        $newBalance = $this->calculator->availableTotal($remaining);

        $this->writeDeductionLedger($leaveRequest, $balance, $allocation['allocations']);
        $this->updateSummaryAfterDeduction($balance, $remaining, $allocation['allocations'], $requested);

        // Audit pemotongan harus gagal bersama ledger/ringkasan agar mutasi saldo tetap dapat ditelusuri utuh.
        $this->auditDeductionOrFail(
            leaveRequest: $leaveRequest,
            balance: $balance,
            sourceYear: $tahun,
            requested: $requested,
            oldBalance: $oldBalance,
            newBalance: $newBalance,
            allocations: $allocation['allocations'],
        );
    }

    /**
     * Menutup saldo tahun sumber dan membuat ringkasan tahun target secara idempotent.
     * `{year}` pada command adalah tahun sumber yang ditutup, sehingga target selalu `{year + 1}`.
     */
    public function rolloverYear(int $sourceYear): void
    {
        $this->rollover->execute($sourceYear, fn (
            Employee $employee,
            LeaveBalance $sourceBalance,
            ?LeaveBalance $targetBalance,
            int $lockedSourceYear,
            int $targetYear,
        ) => $this->rolloverLockedEmployee($employee, $sourceBalance, $targetBalance, $lockedSourceYear, $targetYear));
    }

    /** Menulis rollover setelah orkestrator mengunci request, pegawai, dan kedua saldo. */
    public function rolloverLockedEmployee(
        Employee $employee,
        LeaveBalance $lockedSource,
        ?LeaveBalance $targetBalance,
        int $sourceYear,
        int $targetYear,
    ): void {
        $dedupKey = "{$employee->id}:{$targetYear}:rollover_applied";

        // Guard authoritative berjalan setelah seluruh lock agar retry paralel tidak menulis ulang target.
        if (LeaveBalanceLedger::query()->where('dedup_key', $dedupKey)->exists()) {
            return;
        }

        // Pembukaan admin adalah baseline final untuk tahun target dan tidak boleh dicampur dengan rollover terlambat.
        if (LeaveBalanceLedger::query()
            ->where('employee_id', $employee->id)
            ->where('tahun', $targetYear)
            ->where('event_type', LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET)
            ->exists()) {
            return;
        }

        $buckets = $this->bucketsFromBalance($lockedSource);
        $protected = $this->protectedAllocations($lockedSource->employee_id, $sourceYear);
        $ordinary = $this->subtractProtectedBuckets($buckets, $protected);
        $protectedTotal = $this->calculator->availableTotal($protected);
        $expiredDutyCarryOver = $this->remainingDutyCarryOverExpiringIn(
            $lockedSource->employee_id,
            $sourceYear,
            $ordinary['n1'],
        );
        $ordinary = $this->subtractProtectedBuckets($ordinary, [
            'n2' => 0,
            'n1' => $expiredDutyCarryOver,
            'current' => 0,
        ]);
        $sourceYearNoApprovedAnnualLeave = $this->hasNoApprovedAnnualLeaveInYear(
            $lockedSource->employee_id,
            $sourceYear,
        );
        $twoYearsNoAnnualLeave = $this->hasNoAnnualLeaveForTwoYears(
            $lockedSource->employee_id,
            $sourceYear,
        );
        $rule5Active = $this->hasApprovedCutiBesar($lockedSource->employee_id, $sourceYear);
        // Hak statutory yang telah ditunda dinas tetap memakai jalur Rule 3; hanya current ordinary yang disupresi.
        $sourceCurrent = $rule5Active ? 0 : $ordinary['current'];
        $rule5ExpiredCurrent = $rule5Active ? $ordinary['current'] : 0;
        $result = $this->calculator->calculateRollover(
            previousN1: $ordinary['n1'],
            previousCurrent: $sourceCurrent,
            sourceYearNoApprovedAnnualLeave: $sourceYearNoApprovedAnnualLeave,
            twoYearsNoAnnualLeave: $twoYearsNoAnnualLeave,
            postponedByDuty: $protectedTotal,
        );
        $ordinaryResult = $this->calculator->calculateRollover(
            previousN1: $ordinary['n1'],
            previousCurrent: $sourceCurrent,
            sourceYearNoApprovedAnnualLeave: $sourceYearNoApprovedAnnualLeave,
            twoYearsNoAnnualLeave: $twoYearsNoAnnualLeave,
            postponedByDuty: 0,
        );
        $dutyPostponedCarried = max(
            0,
            ($result['n2'] + $result['n1']) - ($ordinaryResult['n2'] + $ordinaryResult['n1']),
        );
        // Carry-over dari penangguhan dinas hanya hidup satu tahun dan tidak boleh naik menjadi bucket N-2.
        $result['hangus'] += $expiredDutyCarryOver + $rule5ExpiredCurrent;
        $result['rule_5_current_excluded'] = $rule5ExpiredCurrent;

        $systemInitialized = LeaveBalanceLedger::query()
            ->where('employee_id', $lockedSource->employee_id)
            ->where('tahun', $targetYear)
            ->where('event_type', LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED)
            ->exists();

        if ($targetBalance === null) {
            $targetBalance = LeaveBalance::create([
                'employee_id' => $lockedSource->employee_id,
                'tahun' => $targetYear,
                'jatah_awal' => $this->calculator->annualEntitlement(),
                'carry_over' => $result['n2'] + $result['n1'],
                'terpakai' => 0,
                'sisa' => $result['maxUsable'],
                'sisa_n2' => $result['n2'],
                'sisa_n1' => $result['n1'],
                'sisa_tahun_berjalan' => $result['current'],
                'terpakai_tahun_berjalan' => 0,
                'hangus' => $result['hangus'],
            ]);
        } elseif ($systemInitialized) {
            // Entitlement/koreksi/pemotongan target sudah menjadi fakta; rollover hanya menambahkan carry-over sekali.
            $targetN2 = (int) $targetBalance->sisa_n2 + $result['n2'];
            $targetN1 = (int) $targetBalance->sisa_n1 + $result['n1'];
            $targetCurrent = (int) $targetBalance->sisa_tahun_berjalan;
            $targetBalance->forceFill([
                'carry_over' => $targetN2 + $targetN1,
                'sisa' => $targetN2 + $targetN1 + $targetCurrent,
                'sisa_n2' => $targetN2,
                'sisa_n1' => $targetN1,
                'hangus' => $result['hangus'],
            ])->save();
        } else {
            // Baris legacy tanpa event inisialisasi tetap mengikuti perilaku sinkronisasi lama.
            $targetCurrent = max(0, $this->calculator->annualEntitlement() - $targetBalance->terpakai_tahun_berjalan);
            $targetAvailable = $result['n2'] + $result['n1'] + $targetCurrent;
            $targetBalance->forceFill([
                'jatah_awal' => $this->calculator->annualEntitlement(),
                'carry_over' => $result['n2'] + $result['n1'],
                'sisa' => $targetAvailable,
                'sisa_n2' => $result['n2'],
                'sisa_n1' => $result['n1'],
                'sisa_tahun_berjalan' => $targetCurrent,
                'hangus' => $result['hangus'],
            ])->save();
        }

        $this->writeRolloverLedger(
            $lockedSource,
            $targetBalance,
            $sourceYear,
            $targetYear,
            $result,
            $dedupKey,
            $dutyPostponedCarried,
        );
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
            // Employee lock melindungi kondisi baris saldo belum ada dan menyerialkan pembukaan dengan mutasi lain.
            $this->lockEmployee($employeeModel->id);
            $balance = LeaveBalance::query()
                ->where('employee_id', $employeeModel->id)
                ->where('tahun', $tahun)
                ->lockForUpdate()
                ->first();

            if ($this->isInitialized($employeeModel->id, $tahun) || $this->hasBalanceDeduction($employeeModel->id, $tahun)) {
                throw ValidationException::withMessages([
                    'saldo' => 'Saldo awal pegawai sudah tercatat. Gunakan Koreksi Saldo untuk perubahan yang dapat diaudit.',
                ]);
            }

            $balance ??= LeaveBalance::create([
                'employee_id' => $employeeModel->id,
                'tahun' => $tahun,
                'jatah_awal' => 0,
                'carry_over' => 0,
                'terpakai' => 0,
                'sisa' => 0,
            ]);

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

            // Baseline pembukaan adalah fakta immutable; perubahan berikutnya wajib menjadi event koreksi baru.
            LeaveBalanceLedger::create($ledgerPayload);

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
            // Lifecycle diperiksa setelah employee lock agar koreksi tidak membuat baris orphan saat pembukaan paralel.
            $this->lockEmployee($employeeModel->id);

            if (! $this->isInitialized($employeeModel->id, $tahun)) {
                throw ValidationException::withMessages([
                    'saldo' => 'Daftarkan saldo awal pegawai terlebih dahulu sebelum melakukan koreksi.',
                ]);
            }

            $balance = LeaveBalance::query()
                ->where('employee_id', $employeeModel->id)
                ->where('tahun', $tahun)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                throw ValidationException::withMessages([
                    'saldo' => 'Ringkasan saldo hasil inisialisasi tidak ditemukan. Hubungi administrator sistem.',
                ]);
            }
            $buckets = $this->bucketsFromBalance($balance);
            $oldTotal = $this->calculator->availableTotal($buckets);
            $currentBucketValue = $this->bucketValue($buckets, $bucket);

            if ($amount < 0) {
                // Koreksi admin tidak boleh mengurangi hak yang sudah dilindungi untuk penangguhan dinas.
                // Validasi dilakukan setelah lock dan menolak seluruh intent agar koreksi parsial tidak tersamarkan.
                $protectedBuckets = $this->protectedAllocations($employeeModel->id, $tahun);
                $unprotectedBuckets = $this->subtractProtectedBuckets(
                    $buckets,
                    $protectedBuckets,
                );
                $unprotectedBucketValue = $this->bucketValue($unprotectedBuckets, $bucket);

                if ($this->bucketValue($protectedBuckets, $bucket) > 0
                    && abs($amount) > $unprotectedBucketValue) {
                    throw ValidationException::withMessages([
                        'amount' => "Pengurangan bucket {$bucket} melebihi saldo yang tidak dilindungi.",
                    ]);
                }
            }

            $applied = $amount < 0 ? -min(abs($amount), $currentBucketValue) : $amount;

            $remaining = $this->applyBucketDelta($buckets, $bucket, $applied);

            if ($amount < 0) {
                // Koreksi debit juga harus menyisakan kapasitas untuk reservasi aktif yang akan
                // dipotong tertua-dahulu saat approval final, setelah hak penangguhan dilindungi.
                $unprotectedRemaining = $this->subtractProtectedBuckets(
                    $remaining,
                    $protectedBuckets,
                );
                $activeReserved = $this->activeReservedDays($employeeModel->id, $tahun);

                if ($this->calculator->availableTotal($unprotectedRemaining) < $activeReserved) {
                    throw ValidationException::withMessages([
                        'amount' => 'Pengurangan saldo menghapus kapasitas yang sudah dialokasikan untuk pengajuan cuti aktif.',
                    ]);
                }
            }

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
    public function recordDutyPostponement(LeaveRequest $leaveRequest, User $actor, string $reason): LeaveBalanceLedger
    {
        if (! $leaveRequest->exists) {
            throw ValidationException::withMessages([
                'leave_request' => 'Pengajuan cuti harus sudah tersimpan sebelum penangguhan dinas dicatat.',
            ]);
        }

        // Fakta marker wajib dibaca ulang dari database agar model caller yang stale tidak mengubah kontrak ledger.
        $leaveRequest = LeaveRequest::query()->with('jenisCuti')->findOrFail($leaveRequest->id);
        $sourceYear = $leaveRequest->tanggal_mulai->year;
        $days = (int) $leaveRequest->jumlah_hari_kerja;
        $sourceStatus = $leaveRequest->status;

        if ($leaveRequest->jenisCuti?->code !== 'tahunan') {
            throw ValidationException::withMessages([
                'jenis_cuti' => 'Penangguhan dinas hanya dapat melindungi pengajuan cuti tahunan.',
            ]);
        }

        if ($days <= 0) {
            throw ValidationException::withMessages([
                'jumlah_hari' => 'Jumlah hari penangguhan dinas harus lebih dari nol.',
            ]);
        }

        if ($leaveRequest->tanggal_selesai->year !== $sourceYear) {
            throw ValidationException::withMessages([
                'tanggal_selesai' => 'Penangguhan dinas lintas tahun tidak dapat dicatat dalam satu alokasi saldo.',
            ]);
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $reason, $sourceYear, $days, $sourceStatus): LeaveBalanceLedger {
            // Mutex pegawai menyamakan urutan lock dengan rollover agar pencatatan tidak melewati penutupan tahun.
            $this->lockEmployee($leaveRequest->employee_id);

            if (LeaveBalanceLedger::query()
                ->where('employee_id', $leaveRequest->employee_id)
                ->where('source_year', $sourceYear)
                ->where('event_type', LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED)
                ->exists()) {
                throw ValidationException::withMessages([
                    'leave_request' => 'Penangguhan dinas tidak dapat dicatat setelah saldo tahun sumber ditutup.',
                ]);
            }

            $balance = LeaveBalance::query()
                ->where('employee_id', $leaveRequest->employee_id)
                ->where('tahun', $sourceYear)
                ->lockForUpdate()
                ->first();

            if ($balance === null) {
                throw ValidationException::withMessages([
                    'saldo' => 'Saldo tahun sumber tidak ditemukan untuk pencatatan penangguhan dinas.',
                ]);
            }

            $dedupKey = "duty_postponement:{$leaveRequest->id}";
            $existing = LeaveBalanceLedger::query()->where('dedup_key', $dedupKey)->first();

            if ($existing !== null) {
                $this->assertDutyPostponementContract($existing, $leaveRequest, $actor, $sourceYear, $days, $sourceStatus);

                return $existing;
            }

            $protected = $this->protectedAllocations($leaveRequest->employee_id, $sourceYear);
            $unprotected = $this->subtractProtectedBuckets($this->bucketsFromBalance($balance), $protected);
            $expiringDutyCarryOver = $this->remainingDutyCarryOverExpiringIn(
                $leaveRequest->employee_id,
                $sourceYear,
                $unprotected['n1'],
            );
            $allocationCandidate = $this->subtractProtectedBuckets($unprotected, [
                'n2' => 0,
                'n1' => $expiringDutyCarryOver,
                'current' => 0,
            ]);
            $reservedByOtherRequests = $this->activeReservedDaysExcluding(
                $leaveRequest->employee_id,
                $sourceYear,
                $leaveRequest->id,
            );
            $reservationAllocation = $this->calculator->allocateDeduction(
                $allocationCandidate,
                $reservedByOtherRequests,
            );
            $allocationCandidate = $reservationAllocation['success']
                ? $reservationAllocation['remaining']
                : ['n2' => 0, 'n1' => 0, 'current' => 0];
            $allocation = $this->calculator->allocateDutyPostponement($allocationCandidate, $days);

            if (! $allocation['success']) {
                throw ValidationException::withMessages([
                    'jumlah_hari' => 'Jumlah hari penangguhan dinas melebihi saldo sumber yang belum dilindungi.',
                ]);
            }

            $metadata = [
                'request_id' => $leaveRequest->id,
                'protected_days' => $days,
                'protected_allocations' => $allocation['allocations'],
                'source_request_workdays' => $days,
                'source_status' => $sourceStatus,
                'expiry_policy' => 'valid_one_year_no_n2_aging',
            ];
            $ledger = LeaveBalanceLedger::query()->firstOrCreate(
                ['dedup_key' => $dedupKey],
                [
                    'employee_id' => $leaveRequest->employee_id,
                    'leave_request_id' => $leaveRequest->id,
                    'leave_balance_id' => $balance->id,
                    'tahun' => $sourceYear,
                    'event_type' => LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED,
                    'amount' => 0,
                    'source_year' => $sourceYear,
                    'reason' => $reason,
                    'created_by' => $actor->id,
                    'metadata' => $metadata,
                    'occurred_at' => Carbon::now(),
                ],
            );
            $this->assertDutyPostponementContract($ledger, $leaveRequest, $actor, $sourceYear, $days, $sourceStatus);

            return $ledger;
        });
    }

    /**
     * Mengecek konflik Cuti Tahunan sebelum Cuti Besar menjadi persetujuan final.
     *
     * Semua fakta dibaca setelah caller mengunci pegawai. Reservasi dibatasi ke request
     * yang mengurangi saldo tahunan karena tabel event tidak menyimpan jenis cuti secara intrinsik.
     */
    public function assertCutiBesarCanBeFinallyApproved(Employee|string $employee, int $year): void
    {
        $employeeModel = $this->resolveEmployee($employee);

        // Rule 5 dihitung dari fakta Cuti Besar final saat rollover berjalan. Bila Cuti Besar tahun
        // sumber baru final setelah rollover, saldo tahun berjalan sumber sudah terbawa sebagai
        // carry-over dan rollover tidak akan menghitung ulang karena sudah ter-dedup, sehingga
        // persetujuan terlambat harus ditolak agar hak cuti tidak bertambah tanpa dasar.
        $this->assertSourceYearNotRolledOver($employeeModel->id, $year);

        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $yearEnd = $yearStart->copy()->addYear();
        $annualQuery = LeaveRequest::query()
            ->where('employee_id', $employeeModel->id)
            ->where('tanggal_mulai', '>=', $yearStart->toDateString())
            ->where('tanggal_mulai', '<', $yearEnd->toDateString())
            ->whereHas('jenisCuti', fn (Builder $query) => $query->where('mengurangi_saldo_tahunan', true));

        if ((clone $annualQuery)->whereIn('status', LeaveBalanceReservationEvent::activeRequestStatuses())->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Cuti Besar tidak dapat disetujui karena masih ada pengajuan Cuti Tahunan aktif pada tahun yang sama.',
            ]);
        }

        $approved = (clone $annualQuery)->where('status', 'disetujui')->exists();
        $deducted = LeaveBalanceLedger::query()
            ->where('employee_id', $employeeModel->id)
            ->where('event_type', LeaveBalanceLedger::EVENT_LEAVE_DEDUCTED)
            ->where('tahun', $year)
            ->exists();

        if ($approved || $deducted) {
            throw ValidationException::withMessages([
                'status' => 'Cuti Besar tidak dapat disetujui karena Cuti Tahunan tahun yang sama sudah digunakan.',
            ]);
        }

        $reserved = LeaveBalanceReservationEvent::query()
            ->where('employee_id', $employeeModel->id)
            ->where('tahun', $year)
            ->whereHas('leaveRequest.jenisCuti', fn (Builder $query) => $query->where('mengurangi_saldo_tahunan', true))
            ->selectRaw('leave_request_id, SUM(amount) AS reserved_total')
            ->groupBy('leave_request_id')
            ->havingRaw('SUM(amount) > 0')
            ->exists();

        if ($reserved) {
            throw ValidationException::withMessages([
                'status' => 'Cuti Besar tidak dapat disetujui karena saldo Cuti Tahunan masih dialokasikan pada tahun yang sama.',
            ]);
        }
    }

    /** Menolak pemakaian Tahunan pada tahun yang sudah memiliki Cuti Besar final. */
    public function assertAnnualLeaveAllowed(Employee|string $employee, int $year): void
    {
        $employeeModel = $this->resolveEmployee($employee);

        if (! $this->hasApprovedCutiBesar($employeeModel->id, $year)) {
            return;
        }

        throw ValidationException::withMessages([
            'tanggal_mulai' => 'Cuti Tahunan tidak dapat digunakan pada tahun yang sama dengan Cuti Besar yang telah disetujui.',
        ]);
    }

    /**
     * Menolak mutasi dan persetujuan final pada tahun yang saldonya sudah ditutup oleh rollover.
     *
     * Setelah rollover berjalan, sisa hari tahun sumber sudah terbawa sebagai carry-over ke tahun
     * target. Memotong saldo tahun sumber sesudah itu membuat hari yang sama terpakai dua kali dan
     * menambah hak cuti pegawai secara tidak sah. Rollover hanya mengembalikan pengajuan Cuti
     * Tahunan resmi, sehingga jenis pengurang saldo lain yang belum punya jalur pengembalian
     * dihentikan di sini alih-alih dibiarkan memotong saldo yang sudah ditutup.
     *
     * Cuti Besar ikut dijaga di sini karena Rule 5 dievaluasi saat rollover: persetujuan final yang
     * datang belakangan tidak dapat lagi menghanguskan saldo tahun berjalan sumber yang sudah
     * berpindah ke tahun target.
     */
    private function assertSourceYearNotRolledOver(string $employeeId, int $tahun): void
    {
        if (! LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('source_year', $tahun)
            ->where('event_type', LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED)
            ->exists()) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'Pengajuan ini tidak dapat disetujui karena saldo tahun pengajuan sudah ditutup oleh rollover. Ajukan kembali pada tahun berjalan agar saldo yang dipakai sesuai.',
        ]);
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

    /** @return array{n2:int, n1:int, current:int} */
    private function protectedAllocations(string $employeeId, int $sourceYear): array
    {
        // Hanya metadata dibaca karena ledger dapat tumbuh; summary saldo tetap tidak dimutasi oleh perlindungan.
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('source_year', $sourceYear)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->get(['metadata'])
            ->reduce(function (array $total, LeaveBalanceLedger $ledger): array {
                $allocation = $ledger->metadata['protected_allocations'] ?? [];

                foreach (['n2', 'n1', 'current'] as $bucket) {
                    $total[$bucket] += max(0, (int) ($allocation[$bucket] ?? 0));
                }

                return $total;
            }, ['n2' => 0, 'n1' => 0, 'current' => 0]);
    }

    /**
     * Menghitung net reservasi aktif request lain tanpa memuat event ke memori.
     * Reservasi request yang sedang ditangguhkan tetap utuh dan akan dilepas oleh orkestrasi workflow.
     */
    private function activeReservedDaysExcluding(string $employeeId, int $year, string $leaveRequestId): int
    {
        return max(0, (int) LeaveBalanceReservationEvent::query()
            ->forActiveRequests()
            ->where('employee_id', $employeeId)
            ->where('tahun', $year)
            ->where('leave_request_id', '!=', $leaveRequestId)
            ->sum('amount'));
    }

    /** Menghitung net reservasi aktif untuk menjaga koreksi admin tidak mengambil hak yang sudah dialokasikan. */
    private function activeReservedDays(string $employeeId, int $year): int
    {
        return max(0, (int) LeaveBalanceReservationEvent::query()
            ->forActiveRequests()
            ->where('employee_id', $employeeId)
            ->where('tahun', $year)
            ->sum('amount'));
    }

    /**
     * Mengurangi bucket terlindungi tanpa clamp agar ledger rusak tidak menyembunyikan oversubscription.
     *
     * @param  array{n2:int, n1:int, current:int}  $buckets
     * @param  array{n2:int, n1:int, current:int}  $protected
     * @return array{n2:int, n1:int, current:int}
     */
    private function subtractProtectedBuckets(array $buckets, array $protected): array
    {
        $remaining = $buckets;

        foreach (['n2', 'n1', 'current'] as $bucket) {
            if ($protected[$bucket] < 0 || $protected[$bucket] > $buckets[$bucket]) {
                throw ValidationException::withMessages([
                    'saldo' => "Alokasi terlindungi bucket {$bucket} tidak konsisten dengan saldo tersimpan.",
                ]);
            }

            $remaining[$bucket] -= $protected[$bucket];
        }

        return $remaining;
    }

    /**
     * Menggabungkan kembali hari terlindungi ke bucket sisa setelah alokasi non-terlindungi.
     *
     * Perlindungan penangguhan dinas tidak memutasi summary, sehingga hari tersebut harus utuh
     * kembali di ringkasan agar `protectedAllocations()` tetap konsisten dengan saldo tersimpan.
     *
     * @param  array{n2:int, n1:int, current:int}  $remaining
     * @param  array{n2:int, n1:int, current:int}  $protected
     * @return array{n2:int, n1:int, current:int}
     */
    private function mergeProtectedBuckets(array $remaining, array $protected): array
    {
        return [
            'n2' => $remaining['n2'] + $protected['n2'],
            'n1' => $remaining['n1'] + $protected['n1'],
            'current' => $remaining['current'] + $protected['current'],
        ];
    }

    /**
     * Retry hanya idempoten bila ledger existing masih terikat pada kontrak request yang sama.
     */
    private function assertDutyPostponementContract(
        LeaveBalanceLedger $ledger,
        LeaveRequest $leaveRequest,
        User $actor,
        int $sourceYear,
        int $days,
        string $sourceStatus,
    ): void {
        $metadata = $ledger->metadata ?? [];
        $allocations = $metadata['protected_allocations'] ?? [];
        $protectedTotal = array_sum(array_map(
            static fn (string $bucket): int => max(0, (int) ($allocations[$bucket] ?? 0)),
            ['n2', 'n1', 'current'],
        ));
        $matches = $ledger->event_type === LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED
            && $ledger->employee_id === $leaveRequest->employee_id
            && $ledger->leave_request_id === $leaveRequest->id
            && $ledger->tahun === $sourceYear
            && $ledger->source_year === $sourceYear
            && $ledger->created_by === $actor->id
            && ($metadata['request_id'] ?? null) === $leaveRequest->id
            && (int) ($metadata['protected_days'] ?? 0) === $days
            && (int) ($metadata['source_request_workdays'] ?? 0) === $days
            && ($metadata['source_status'] ?? null) === $sourceStatus
            && $protectedTotal === $days
            && ($metadata['expiry_policy'] ?? null) === 'valid_one_year_no_n2_aging';

        if (! $matches) {
            throw ValidationException::withMessages([
                'leave_request' => 'Ledger penangguhan dinas existing tidak sesuai dengan kontrak pengajuan.',
            ]);
        }
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

    /**
     * Inisialisasi hanya diakui dari event pembukaan resmi atau pembuatan hak oleh sistem.
     * Koreksi manual legacy sengaja tidak termasuk agar tidak melegitimasi saldo orphan.
     */
    private function isInitialized(string $employeeId, int $tahun): bool
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('tahun', $tahun)
            ->whereIn('event_type', $this->initializationEventTypes())
            ->exists();
    }

    /** @return list<string> */
    private function initializationEventTypes(): array
    {
        return [
            LeaveBalanceLedger::EVENT_OPENING_BALANCE_SET,
            LeaveBalanceLedger::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
            LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
        ];
    }

    private function lockEmployee(string $employeeId): Employee
    {
        return Employee::query()->whereKey($employeeId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Eligibility carry ordinary memakai fakta request tahunan disetujui berdasarkan tanggal mulai.
     * Rentang setengah terbuka menjaga predicate tetap sargable tanpa fungsi whereYear pada kolom.
     */
    private function hasNoApprovedAnnualLeaveInYear(string $employeeId, int $year): bool
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = $start->copy()->addYear();

        return ! $this->approvedAnnualLeaveQuery($employeeId)
            ->where('tanggal_mulai', '>=', $start->toDateString())
            ->where('tanggal_mulai', '<', $end->toDateString())
            ->exists();
    }

    /**
     * Aging ke N-2 hanya aktif bila tahun sumber dan tahun sebelumnya tanpa approval tahunan.
     */
    private function hasNoAnnualLeaveForTwoYears(string $employeeId, int $sourceYear): bool
    {
        return $this->hasNoApprovedAnnualLeaveInYear($employeeId, $sourceYear - 1)
            && $this->hasNoApprovedAnnualLeaveInYear($employeeId, $sourceYear);
    }

    /**
     * Menentukan status Rule 5 dari fakta approval final pada tahun penggunaan.
     *
     * Surface baca memakai flag ini untuk membedakan saldo tersimpan yang auditabel
     * dari hak Cuti Tahunan efektif tanpa melakukan mutasi saldo atau ledger.
     */
    public function hasApprovedCutiBesar(string $employeeId, int $year): bool
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = $start->copy()->addYear();

        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'disetujui')
            ->where('tanggal_mulai', '>=', $start->toDateString())
            ->where('tanggal_mulai', '<', $end->toDateString())
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

    /**
     * Membatasi expiry ke live N-1 karena hari statutory yang sudah dipakai tidak boleh hangus lagi.
     * Saat N-1 mencampur sumber, sisa live diatribusikan ke statutory lebih dahulu agar tidak pernah menua ke N-2.
     */
    private function remainingDutyCarryOverExpiringIn(string $employeeId, int $sourceYear, int $liveN1): int
    {
        $granted = LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('tahun', $sourceYear)
            ->where('event_type', LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED)
            ->get(['metadata'])
            ->sum(fn (LeaveBalanceLedger $ledger): int => (int) ($ledger->metadata['duty_postponed_carried'] ?? 0));

        return min(max(0, $granted), max(0, $liveN1));
    }

    private function resolveEmployee(Employee|string $employee): Employee
    {
        if ($employee instanceof Employee) {
            return $employee->loadMissing('appointment');
        }

        return Employee::query()->with('appointment')->findOrFail($employee);
    }

    /**
     * Menentukan kelayakan entitlement tanpa menulis baris saldo. Digunakan khusus
     * oleh preview read-only; mutasi tetap memakai `ensureAnnualEntitlement()`.
     */
    private function isEligibleForAnnualEntitlement(Employee $employee, Carbon $asOf): bool
    {
        $tmt = $employee->appointment?->tmt_pengangkatan;

        return $tmt !== null
            && $tmt->copy()->addYear()->startOfDay()->lessThanOrEqualTo($asOf->copy()->startOfDay());
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
            // Mutex pegawai menyatukan urutan lock dengan pembukaan, koreksi, rollover, dan reservasi.
            $this->lockEmployee($employee->id);
            $balance = LeaveBalance::query()
                ->where('employee_id', $employee->id)
                ->where('tahun', $tahun)
                ->lockForUpdate()
                ->first();

            if ($balance !== null) {
                return $balance;
            }

            $balance = LeaveBalance::create([
                'employee_id' => $employee->id,
                'tahun' => $tahun,
                'jatah_awal' => $this->calculator->annualEntitlement(),
                'carry_over' => 0,
                'terpakai' => 0,
                'sisa' => $this->calculator->annualEntitlement(),
                'sisa_n2' => 0,
                'sisa_n1' => 0,
                'sisa_tahun_berjalan' => $this->calculator->annualEntitlement(),
                'terpakai_tahun_berjalan' => 0,
                'hangus' => 0,
            ]);

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
     * @param  array{n2:int, n1:int, current:int, hangus:int, maxUsable:int, rule_5_current_excluded:int}  $result
     */
    private function writeRolloverLedger(
        LeaveBalance $sourceBalance,
        LeaveBalance $targetBalance,
        int $sourceYear,
        int $targetYear,
        array $result,
        string $rolloverKey,
        int $dutyPostponedCarried,
    ): void {
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
                    'rule_5_current_excluded' => $result['rule_5_current_excluded'],
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
                        'duty_postponed_carried' => $dutyPostponedCarried,
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
