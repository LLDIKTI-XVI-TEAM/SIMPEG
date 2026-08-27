<?php

namespace App\Services\Cuti;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveUsageRecord;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class LeaveUsageOverlapService
{
    /** @var list<string> */
    public const ACTIVE_REQUEST_STATUSES = [
        'menunggu_approval',
        'ditangguhkan',
        'perlu_perubahan',
        'ditangguhkan_tugas_dinas',
        LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
        'disetujui',
    ];

    /** Mutex pegawai harus selalu menjadi lock pertama pada setiap jalur mutasi periode cuti. */
    public function lockEmployee(string|Employee $employee): Employee
    {
        $id = $employee instanceof Employee ? $employee->id : $employee;

        return Employee::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** Menolak irisan inklusif lintas jenis antara request aktif dan fakta manual aktif. */
    public function assertNoOverlap(
        Employee $employee,
        Carbon $startDate,
        Carbon $endDate,
        ?string $excludingManualId = null,
        ?string $excludingLeaveRequestId = null,
    ): void {
        $requestOverlap = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', self::ACTIVE_REQUEST_STATUSES)
            ->when($excludingLeaveRequestId !== null, fn ($query) => $query->where('id', '!=', $excludingLeaveRequestId))
            ->whereDate('tanggal_mulai', '<=', $endDate->toDateString())
            ->whereDate('tanggal_selesai', '>=', $startDate->toDateString())
            ->exists();
        $manualOverlap = LeaveUsageRecord::query()
            ->where('employee_id', $employee->id)
            ->where('source_type', LeaveUsageRecord::SOURCE_MANUAL_EXTERNAL)
            ->where('record_status', LeaveUsageRecord::STATUS_ACTIVE)
            ->when($excludingManualId !== null, fn ($query) => $query->where('id', '!=', $excludingManualId))
            ->whereDate('start_date', '<=', $endDate->toDateString())
            ->whereDate('end_date', '>=', $startDate->toDateString())
            ->exists();

        if ($requestOverlap || $manualOverlap) {
            throw ValidationException::withMessages([
                'tanggal_mulai' => 'Periode cuti beririsan dengan pengajuan atau pemakaian manual aktif lain.',
            ]);
        }
    }
}
