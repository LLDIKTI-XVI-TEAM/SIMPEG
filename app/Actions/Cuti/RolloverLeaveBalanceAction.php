<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\DB;

/**
 * Mengorkestrasi rollover per pegawai dengan urutan lock yang seragam.
 *
 * Request dikunci lebih dahulu agar resubmit tidak dapat mengubah lifecycle saat
 * rollover berjalan, kemudian mutex pegawai dan ringkasan saldo dikunci sebelum
 * pengajuan tahun sumber dikembalikan dan carry-over dihitung.
 */
class RolloverLeaveBalanceAction
{
    private const ACTIVE_STATUSES = [
        'menunggu_approval',
        'ditangguhkan',
        'perlu_perubahan',
    ];

    public function __construct(
        private readonly ReturnActiveLeaveRequestsForRolloverAction $returnActiveRequests,
    ) {}

    /** Menjalankan rollover sumber secara bertahap agar seluruh saldo tidak dimuat sekaligus. */
    /**
     * @param  \Closure(Employee, LeaveBalance, LeaveBalance|null, int, int): void  $rolloverEmployee
     */
    public function execute(int $sourceYear, \Closure $rolloverEmployee): void
    {
        $targetYear = $sourceYear + 1;

        LeaveBalance::query()
            ->where('tahun', $sourceYear)
            ->select(['id', 'employee_id'])
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (LeaveBalance $candidate) use ($rolloverEmployee, $sourceYear, $targetYear): void {
                DB::transaction(function () use ($candidate, $rolloverEmployee, $sourceYear, $targetYear): void {
                    $requests = LeaveRequest::query()
                        ->with('jenisCuti')
                        ->where('employee_id', $candidate->employee_id)
                        ->where('tanggal_mulai', '>=', "{$sourceYear}-01-01")
                        ->where('tanggal_mulai', '<', "{$targetYear}-01-01")
                        ->whereIn('status', self::ACTIVE_STATUSES)
                        // Rollover hanya untuk Cuti Tahunan resmi, bukan semua pengurang saldo.
                        ->whereHas('jenisCuti', fn ($query) => $query->where('code', 'tahunan'))
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();
                    $employee = Employee::query()->whereKey($candidate->employee_id)->lockForUpdate()->firstOrFail();
                    $sourceBalance = LeaveBalance::query()
                        ->whereKey($candidate->id)
                        ->where('employee_id', $employee->id)
                        ->where('tahun', $sourceYear)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $targetBalance = LeaveBalance::query()
                        ->where('employee_id', $employee->id)
                        ->where('tahun', $targetYear)
                        ->lockForUpdate()
                        ->first();

                    $this->returnActiveRequests->executeLocked(
                        $requests,
                        $employee,
                        $sourceBalance,
                        $sourceYear,
                        $targetYear,
                    );
                    $rolloverEmployee(
                        $employee,
                        $sourceBalance,
                        $targetBalance,
                        $sourceYear,
                        $targetYear,
                    );
                });
            });
    }
}
