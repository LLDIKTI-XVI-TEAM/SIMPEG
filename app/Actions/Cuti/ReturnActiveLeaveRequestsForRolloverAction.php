<?php

namespace App\Actions\Cuti;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mengembalikan pengajuan Tahunan aktif tahun sumber sebelum saldo dibawa ke tahun target.
 * Snapshot step sengaja tidak diubah supaya approver aktif tetap sama ketika pegawai mengirim ulang.
 */
class ReturnActiveLeaveRequestsForRolloverAction
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Mengembalikan request yang telah dikunci oleh orkestrator rollover.
     *
     * @param  Collection<int, LeaveRequest>  $requests
     */
    public function executeLocked(
        Collection $requests,
        Employee $employee,
        LeaveBalance $sourceBalance,
        int $sourceYear,
        int $targetYear,
    ): int {
        $returnedRequestIds = [];

        foreach ($requests as $leaveRequest) {
            $statusBefore = $leaveRequest->status;
            $release = $this->releaseReservationLocked($leaveRequest, $sourceBalance, $sourceYear, $targetYear);
            $leaveRequest->forceFill([
                'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
                'rollover_source_year' => $sourceYear,
                'rollover_target_year' => $targetYear,
            ])->save();

            // Status pengembalian dan audit pelepasan reservasi harus tersimpan dalam transaksi yang sama.
            AuditLog::create([
                'event' => 'UPDATE',
                'auditable_type' => 'LeaveRequest',
                'auditable_id' => $leaveRequest->id,
                'old_values' => [
                    'status' => $statusBefore,
                    'rollover_source_year' => null,
                    'rollover_target_year' => null,
                ],
                'new_values' => [
                    'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
                    'rollover_source_year' => $sourceYear,
                    'rollover_target_year' => $targetYear,
                    'reservation_event_id' => $release?->id,
                ],
            ]);
            $returnedRequestIds[] = $leaveRequest->id;
        }

        if ($returnedRequestIds === []) {
            return 0;
        }

        $detailRequestId = $returnedRequestIds[0];

        // Satu delivery per pegawai dijadwalkan setelah commit agar rollback rollover tidak memberi informasi keliru.
        DB::afterCommit(function () use ($detailRequestId, $employee, $returnedRequestIds, $sourceYear, $targetYear): void {
            $this->notifications->createForEmployee(
                $employee,
                'cuti.dikembalikan_karena_rollover',
                'Pengajuan Cuti Dikembalikan karena Rollover',
                "Pengajuan Cuti Tahunan tahun {$sourceYear} dikembalikan. Perbaiki tanggal dan ajukan kembali pada tahun {$targetYear}.",
                [
                    'leave_request_id' => $detailRequestId,
                    'leave_request_ids' => $returnedRequestIds,
                    'reason' => 'Pengajuan dikembalikan karena rollover saldo cuti tahunan.',
                    'source_year' => $sourceYear,
                    'target_year' => $targetYear,
                    'url' => route('cuti.show', ['id' => $detailRequestId], false),
                ],
            );
        });

        return count($returnedRequestIds);
    }

    /** Melepas reservasi append-only setelah request, pegawai, dan saldo sumber telah terkunci. */
    private function releaseReservationLocked(
        LeaveRequest $leaveRequest,
        LeaveBalance $sourceBalance,
        int $sourceYear,
        int $targetYear,
    ): ?LeaveBalanceReservationEvent {
        $events = LeaveBalanceReservationEvent::query()
            ->where('leave_request_id', $leaveRequest->id)
            ->where('tahun', $sourceYear)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $dedupKey = "leave_reservation:{$leaveRequest->id}:released:rollover:{$sourceYear}";
        $existing = $events->firstWhere('dedup_key', $dedupKey);

        if ($existing instanceof LeaveBalanceReservationEvent) {
            return $existing;
        }

        $reserved = (int) $events->sum('amount');

        if ($reserved <= 0) {
            return null;
        }

        $event = LeaveBalanceReservationEvent::create([
            'employee_id' => $leaveRequest->employee_id,
            'leave_request_id' => $leaveRequest->id,
            'leave_balance_id' => $sourceBalance->id,
            'tahun' => $sourceYear,
            'event_type' => LeaveBalanceReservationEvent::EVENT_RELEASED,
            'amount' => -$reserved,
            'reason' => 'Reservasi cuti tahunan tahun sumber dilepas karena pengajuan dikembalikan saat rollover.',
            'dedup_key' => $dedupKey,
            'metadata' => [
                'release_context' => 'rollover_return',
                'source_year' => $sourceYear,
                'target_year' => $targetYear,
            ],
            'occurred_at' => now(),
        ]);

        AuditLog::create([
            'event' => 'LEAVE_BALANCE_RESERVATION_RELEASED',
            'auditable_type' => LeaveBalanceReservationEvent::class,
            'auditable_id' => $event->id,
            'old_values' => [
                'leave_request_id' => $leaveRequest->id,
                'allocated_days' => $reserved,
                'release_context' => 'rollover_return',
            ],
            'new_values' => [
                'leave_request_id' => $leaveRequest->id,
                'allocated_days' => 0,
                'release_context' => 'rollover_return',
                'source_year' => $sourceYear,
                'target_year' => $targetYear,
            ],
        ]);

        return $event;
    }
}
