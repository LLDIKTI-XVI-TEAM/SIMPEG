<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use App\Models\RefJenisCuti;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
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
        private readonly AnnualLeaveEligibilityPolicy $annualEligibility,
        private readonly AnnualLeaveBusinessClock $businessClock,
    ) {}

    /**
     * Mengecek saldo tersedia tanpa mengunci baris dan tanpa reservasi.
     * Dipakai saat submit/resubmit agar validasi awal konsisten dengan mesin deduction final.
     */
    public function availableFor(Employee|string $employee, int $tahun, ?Carbon $asOf = null): int
    {
        $employeeModel = $this->resolveEmployee($employee);
        $effectiveAsOf = $asOf ?? $this->businessClock->now();

        if (! $this->annualEligibility->isEligible($employeeModel, $effectiveAsOf)) {
            return 0;
        }

        if ($this->hasApprovedCutiBesar($employeeModel->id, $tahun)) {
            return 0;
        }

        $balance = LeaveBalance::query()
            ->where('employee_id', $employeeModel->id)
            ->where('tahun', $tahun)
            ->first();

        if ($balance === null) {
            return 0;
        }

        $protected = $this->protectedAllocations($employeeModel->id, $tahun);
        $available = $this->subtractProtectedBuckets($this->bucketsFromBalance($balance), $protected);

        return $this->calculator->availableTotal($available);
    }

    /**
     * Menyusun angka ketersediaan efektif dari projection dan dua alokasi non-final.
     *
     * Ringkasan ini dipakai bersama oleh preview pengajuan dan workspace administrasi
     * agar urutan pengurangan reservasi serta hak penangguhan dinas tidak menyimpang.
     *
     * @param  array{n2:int,n1:int,current:int}  $buckets
     * @return array{saldo_aktual:int,dialokasikan_aktif:int,dilindungi_penangguhan_dinas:int,saldo_dapat_diajukan:int}
     */
    public function availabilitySummary(array $buckets, int $activeReservations, int $dutyProtected): array
    {
        $saldoAktual = $this->calculator->availableTotal($buckets);
        $dialokasikanAktif = max(0, $activeReservations);
        $dilindungiPenangguhanDinas = max(0, $dutyProtected);

        return [
            'saldo_aktual' => $saldoAktual,
            'dialokasikan_aktif' => $dialokasikanAktif,
            'dilindungi_penangguhan_dinas' => $dilindungiPenangguhanDinas,
            'saldo_dapat_diajukan' => max(
                0,
                $saldoAktual - $dialokasikanAktif - $dilindungiPenangguhanDinas,
            ),
        ];
    }

    /**
     * Menyusun preview saldo tanpa menulis entitlement atau ledger dari endpoint GET.
     *
     * Kontrak preview hanya membaca projection hasil replay. Ketiadaan projection berarti
     * saldo belum dapat dipakai dan harus ditampilkan nol tanpa entitlement virtual.
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
                'saldo_aktual' => 0,
                'dialokasikan_aktif' => 0,
                'dilindungi_penangguhan_dinas' => 0,
                'saldo_dapat_diajukan' => 0,
                'rule_5_active' => true,
                'bucket' => ['n2' => 0, 'n1' => 0, 'current' => 0],
            ];
        }

        $eligible = $balance !== null && $this->annualEligibility->isEligible($employeeModel, $asOf);

        if (! $eligible) {
            return [
                'tahun' => $tahun,
                'tanggal_acuan' => $asOf->toDateString(),
                'eligible' => false,
                'jatah_dasar' => 0,
                'carry_over' => 0,
                'terpakai_final' => (int) ($balance?->terpakai ?? 0),
                'used_n1' => $usedN1,
                'used_n2' => $usedN2,
                'saldo_aktual' => 0,
                'dialokasikan_aktif' => 0,
                'dilindungi_penangguhan_dinas' => 0,
                'saldo_dapat_diajukan' => 0,
                'rule_5_active' => false,
                'bucket' => ['n2' => 0, 'n1' => 0, 'current' => 0],
            ];
        }

        $buckets = $balance === null
            ? ['n2' => 0, 'n1' => 0, 'current' => 0]
            : $this->bucketsFromBalance($balance);
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

        $availability = $this->availabilitySummary(
            $buckets,
            (int) $activeReservations->sum('amount'),
            $this->calculator->availableTotal(
                $this->protectedAllocations($employeeModel->id, $tahun),
            ),
        );

        return [
            'tahun' => $tahun,
            'tanggal_acuan' => $asOf->toDateString(),
            'eligible' => $eligible,
            'jatah_dasar' => (int) ($balance?->jatah_awal ?? 0),
            'carry_over' => $buckets['n2'] + $buckets['n1'],
            'terpakai_final' => (int) ($balance?->terpakai ?? 0),
            'used_n1' => $usedN1,
            'used_n2' => $usedN2,
            'saldo_aktual' => $availability['saldo_aktual'],
            'dialokasikan_aktif' => $availability['dialokasikan_aktif'],
            'dilindungi_penangguhan_dinas' => $availability['dilindungi_penangguhan_dinas'],
            'saldo_dapat_diajukan' => $availability['saldo_dapat_diajukan'],
            'rule_5_active' => false,
            'bucket' => $buckets,
        ];
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
            ->whereHas('jenisCuti', fn (Builder $query) => $query->where('code', RefJenisCuti::CODE_TAHUNAN));

        if ((clone $annualQuery)->whereIn('status', LeaveBalanceReservationEvent::activeRequestStatuses())->exists()) {
            throw ValidationException::withMessages([
                'status' => 'Cuti Besar tidak dapat disetujui karena masih ada pengajuan Cuti Tahunan aktif pada tahun yang sama.',
            ]);
        }

        $used = LeaveUsageRecord::query()
            ->where('employee_id', $employeeModel->id)
            ->where('usage_year', $year)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->where('workdays', '>', 0)
            ->whereHas('jenisCuti', fn (Builder $query) => $query->where('code', RefJenisCuti::CODE_TAHUNAN))
            ->exists();

        if ($used) {
            throw ValidationException::withMessages([
                'status' => 'Cuti Besar tidak dapat disetujui karena Cuti Tahunan tahun yang sama sudah digunakan.',
            ]);
        }

        $reserved = LeaveBalanceReservationEvent::query()
            ->where('employee_id', $employeeModel->id)
            ->where('tahun', $year)
            ->whereHas('leaveRequest.jenisCuti', fn (Builder $query) => $query->where('code', RefJenisCuti::CODE_TAHUNAN))
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
        $this->assertSourceYearNotRolledOver($employeeModel->id, $year);

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
     * Membaca bucket projection tanpa fallback dari kolom summary lama.
     * Projection yang belum lengkap harus fail-closed agar saldo tidak tercipta dari data turunan usang.
     *
     * @return array{n2:int, n1:int, current:int}
     */
    private function bucketsFromBalance(LeaveBalance $balance): array
    {
        $sisaN2 = (int) ($balance->sisa_n2 ?? 0);
        $sisaN1 = (int) ($balance->sisa_n1 ?? 0);
        $sisaCurrent = (int) ($balance->sisa_tahun_berjalan ?? 0);

        return [
            'n2' => $sisaN2,
            'n1' => $sisaN1,
            'current' => $sisaCurrent,
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

    private function lockEmployee(string $employeeId): Employee
    {
        return Employee::query()->whereKey($employeeId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Menentukan status Rule 5 dari fakta pemakaian Cuti Besar aktif pada tahun eksak.
     *
     * Surface baca memakai flag ini untuk membedakan saldo tersimpan yang auditabel
     * dari hak Cuti Tahunan efektif tanpa bergantung pada lifecycle pengajuan.
     */
    public function hasApprovedCutiBesar(string $employeeId, int $year): bool
    {
        return LeaveUsageRecord::query()
            ->where('employee_id', $employeeId)
            ->where('usage_year', $year)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->where('workdays', '>', 0)
            ->whereHas('jenisCuti', fn (Builder $query) => $query->where('code', 'besar'))
            ->exists();
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
            return $employee->loadMissing('appointments');
        }

        return Employee::query()->with('appointments')->findOrFail($employee);
    }
}
