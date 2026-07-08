<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
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

        return $balance === null ? 0 : $this->calculator->availableTotal($this->bucketsFromBalance($balance));
    }

    /**
     * Memotong saldo tahunan saat approval final.
     *
     * Aturan penting: method ini menggantikan path lama yang langsung mengurangi `sisa`.
     * Jangan panggil bersama pemotongan summary lama, karena itu akan membuat saldo terdebit dua kali.
     */
    public function deductForFinalApproval(LeaveRequest $leaveRequest): void
    {
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

        $balance = LeaveBalance::query()
            ->where('employee_id', $leaveRequest->employee_id)
            ->where('tahun', $tahun)
            ->lockForUpdate()
            ->first();

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

        $this->writeDeductionLedger($leaveRequest, $balance, $allocation['allocations']);
        $this->updateSummaryAfterDeduction($balance, $allocation['remaining'], $allocation['allocations'], $requested);
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
        $bucketTotal = $balance->sisa_n2 + $balance->sisa_n1 + $balance->sisa_tahun_berjalan;

        if ($bucketTotal === 0 && $balance->sisa > 0) {
            $n1 = min($balance->carry_over, $balance->sisa);

            return [
                'n2' => 0,
                'n1' => $n1,
                'current' => $balance->sisa - $n1,
            ];
        }

        return [
            'n2' => $balance->sisa_n2,
            'n1' => $balance->sisa_n1,
            'current' => $balance->sisa_tahun_berjalan,
        ];
    }

    private function alreadyDeducted(LeaveRequest $leaveRequest): bool
    {
        return LeaveBalanceLedger::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('event_type', 'leave_deducted')
            ->exists();
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
                    'event_type' => 'annual_entitlement_granted',
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
                'event_type' => 'leave_deducted',
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
