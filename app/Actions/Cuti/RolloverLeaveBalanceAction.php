<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceLedger;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\Cuti\LeaveBalanceRecalculationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Mengorkestrasi rollover per pegawai dengan urutan lock yang seragam.
 *
 * Request dikunci lebih dahulu agar resubmit tidak dapat mengubah lifecycle saat
 * rollover berjalan, kemudian mutex pegawai dan ringkasan saldo dikunci sebelum
 * pengajuan tahun sumber dikembalikan dan carry-over dihitung.
 */
class RolloverLeaveBalanceAction
{
    private const PROCESSABLE_STATUSES = [
        'menunggu_approval',
        'ditangguhkan',
    ];

    private const LOCKED_STATUSES = [
        'menunggu_approval',
        'ditangguhkan',
        LeaveRequest::STATUS_CANCELLATION_PENDING,
    ];

    public function __construct(
        private readonly ReturnActiveLeaveRequestsForRolloverAction $returnActiveRequests,
        private readonly LeaveBalanceRecalculationService $recalculation,
    ) {}

    /**
     * Menjalankan rollover sumber secara bertahap agar seluruh saldo tidak dimuat sekaligus.
     *
     * @return array{
     *     attempted:int,
     *     processed:int,
     *     failed:int,
     *     failures:list<array{employee_id:string,source_year:int,exception_class:string,correlation_id:string}>
     * }
     */
    public function execute(int $sourceYear): array
    {
        $targetYear = $sourceYear + 1;
        /** @var array{attempted:int,processed:int,failed:int,failures:list<array{employee_id:string,source_year:int,exception_class:string,correlation_id:string}>} $result */
        $result = [
            'attempted' => 0,
            'processed' => 0,
            'failed' => 0,
            'failures' => [],
        ];

        LeaveBalance::query()
            ->where('tahun', $sourceYear)
            ->select(['id', 'employee_id'])
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (LeaveBalance $candidate) use ($sourceYear, $targetYear, &$result): void {
                $result['attempted']++;

                try {
                    DB::transaction(function () use ($candidate, $sourceYear, $targetYear): void {
                        // Scan awal mempertahankan pagar request -> employee untuk request yang sudah ada.
                        $this->lockedAnnualRequests($candidate->employee_id, $sourceYear, $targetYear)->get();
                        $employee = Employee::query()->whereKey($candidate->employee_id)->lockForUpdate()->firstOrFail();
                        // Submit baru dapat belum terlihat pada scan awal lalu commit sebelum mutex pegawai didapat.
                        // Scan identik kedua menutup celah itu dan menjadi satu-satunya koleksi yang diproses.
                        $lockedRequests = $this->lockedAnnualRequests($employee->id, $sourceYear, $targetYear)->get();

                        // Hold pembatalan harus diselesaikan admin sebelum rollover mengubah reservasi atau saldo.
                        if ($lockedRequests->contains('status', LeaveRequest::STATUS_CANCELLATION_PENDING)) {
                            throw ValidationException::withMessages([
                                'rollover' => 'Rollover ditunda karena permohonan pembatalan cuti masih menunggu keputusan.',
                            ]);
                        }

                        $requests = $lockedRequests
                            ->whereIn('status', self::PROCESSABLE_STATUSES)
                            ->values();
                        $sourceBalance = LeaveBalance::query()
                            ->whereKey($candidate->id)
                            ->where('employee_id', $employee->id)
                            ->where('tahun', $sourceYear)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $rolloverKey = "{$employee->id}:{$targetYear}:rollover_applied";

                        // Marker diperiksa sebelum efek apa pun agar retry tidak mengulang status,
                        // notifikasi, audit, atau replay yang sudah berhasil commit.
                        if (LeaveBalanceLedger::query()->where('dedup_key', $rolloverKey)->exists()) {
                            return;
                        }

                        $this->returnActiveRequests->executeLocked(
                            $requests,
                            $employee,
                            $sourceBalance,
                            $sourceYear,
                            $targetYear,
                        );

                        $this->recalculation->recalculateForRollover(
                            $employee,
                            $sourceYear,
                            "Rollover saldo cuti tahunan {$sourceYear} ke {$targetYear}.",
                            'SIMPEG Scheduler',
                        );

                        $targetBalance = LeaveBalance::query()
                            ->where('employee_id', $employee->id)
                            ->where('tahun', $targetYear)
                            ->lockForUpdate()
                            ->first();

                        if ($targetBalance === null) {
                            throw ValidationException::withMessages([
                                'rollover' => "Projection saldo tahun {$targetYear} tidak terbentuk dari fakta pemakaian.",
                            ]);
                        }

                        $this->writeCarryLedger($sourceBalance, $targetBalance, $sourceYear, $targetYear);
                        $marker = LeaveBalanceLedger::query()->create([
                            'employee_id' => $employee->id,
                            'leave_balance_id' => $targetBalance->id,
                            'tahun' => $targetYear,
                            'event_type' => LeaveBalanceLedger::EVENT_ROLLOVER_APPLIED,
                            'amount' => 0,
                            'source_year' => $sourceYear,
                            'reason' => 'Rollover saldo cuti tahunan berdasarkan replay fakta pemakaian.',
                            'dedup_key' => $rolloverKey,
                            'metadata' => [
                                'source_balance_id' => $sourceBalance->id,
                                'target_year' => $targetYear,
                                'system_actor' => 'SIMPEG Scheduler',
                            ],
                            'occurred_at' => now(),
                        ]);

                        // Audit marker menjadi penutup transaksi; kegagalan jejak sistem wajib
                        // membatalkan return request, reservasi, replay, ledger, dan notifikasi.
                        AuditService::logSystemOrFail(
                            'SIMPEG Scheduler',
                            'LEAVE_ROLLOVER_APPLIED',
                            'LeaveBalance',
                            $targetBalance->id,
                            [
                                'employee_id' => $employee->id,
                                'source_year' => $sourceYear,
                                'source_projection' => $this->projectionSnapshot($sourceBalance),
                            ],
                            [
                                'operation' => 'rollover_applied',
                                'employee_id' => $employee->id,
                                'source_year' => $sourceYear,
                                'target_year' => $targetYear,
                                'target_projection' => $this->projectionSnapshot($targetBalance),
                                'marker_ledger_id' => $marker->id,
                            ],
                        );
                    });
                    $result['processed']++;
                } catch (Throwable $exception) {
                    // Pesan exception dapat memuat query atau data pegawai, sehingga hasil hanya membawa metadata aman.
                    $result['failed']++;
                    $result['failures'][] = [
                        'employee_id' => $candidate->employee_id,
                        'source_year' => $sourceYear,
                        'exception_class' => $exception::class,
                        'correlation_id' => (string) Str::uuid(),
                    ];
                }
            });

        return $result;
    }

    /** Membentuk scan request yang sama pada kedua sisi mutex pegawai, termasuk hold pembatalan. */
    private function lockedAnnualRequests(string $employeeId, int $sourceYear, int $targetYear): Builder
    {
        return LeaveRequest::query()
            ->with('jenisCuti')
            ->where('employee_id', $employeeId)
            ->where('tanggal_mulai', '>=', "{$sourceYear}-01-01")
            ->where('tanggal_mulai', '<', "{$targetYear}-01-01")
            ->whereIn('status', self::LOCKED_STATUSES)
            // Rollover hanya untuk Cuti Tahunan resmi, bukan semua pengurang saldo.
            ->whereHas('jenisCuti', fn ($query) => $query->where('code', 'tahunan'))
            ->orderBy('id')
            ->lockForUpdate();
    }

    /** Menulis jejak carry hasil replay tanpa menjadikannya sumber perhitungan projection. */
    private function writeCarryLedger(
        LeaveBalance $sourceBalance,
        LeaveBalance $targetBalance,
        int $sourceYear,
        int $targetYear,
    ): void {
        $carry = (int) $targetBalance->sisa_n2 + (int) $targetBalance->sisa_n1;
        $dutyPostponedCarried = $this->dutyPostponedCarried($sourceBalance->employee_id, $sourceYear);

        if ($carry <= 0) {
            return;
        }

        LeaveBalanceLedger::query()->create([
            'employee_id' => $sourceBalance->employee_id,
            'leave_balance_id' => $targetBalance->id,
            'tahun' => $targetYear,
            'event_type' => LeaveBalanceLedger::EVENT_CARRY_OVER_GRANTED,
            'amount' => $carry,
            'source_year' => $sourceYear,
            'reason' => 'Carry-over saldo cuti dibentuk dari replay fakta pemakaian.',
            'dedup_key' => "{$sourceBalance->employee_id}:{$targetYear}:carry_over_granted:{$sourceYear}",
            'metadata' => [
                'n2' => (int) $targetBalance->sisa_n2,
                'n1' => (int) $targetBalance->sisa_n1,
                'duty_postponed_carried' => $dutyPostponedCarried,
                'system_actor' => 'SIMPEG Scheduler',
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * Menandai bagian carry statutory agar hak yang hanya berlaku satu tahun tidak dapat dilindungi ulang.
     */
    private function dutyPostponedCarried(string $employeeId, int $sourceYear): int
    {
        return LeaveBalanceLedger::query()
            ->where('employee_id', $employeeId)
            ->where('source_year', $sourceYear)
            ->where('event_type', LeaveBalanceLedger::EVENT_DUTY_POSTPONEMENT_RECORDED)
            ->get(['metadata'])
            ->sum(function (LeaveBalanceLedger $ledger): int {
                $allocations = $ledger->metadata['protected_allocations'] ?? [];

                return collect(['n2', 'n1', 'current'])
                    ->sum(fn (string $bucket): int => max(0, (int) ($allocations[$bucket] ?? 0)));
            });
    }

    /** @return array<string, int> */
    private function projectionSnapshot(LeaveBalance $balance): array
    {
        return collect($balance->only([
            'tahun',
            'jatah_awal',
            'carry_over',
            'terpakai',
            'sisa',
            'sisa_n2',
            'sisa_n1',
            'sisa_tahun_berjalan',
            'terpakai_tahun_berjalan',
            'hangus',
        ]))->map(fn (mixed $value): int => (int) $value)->all();
    }
}
