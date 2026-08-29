<?php

namespace App\Actions\Cuti;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceReservationEvent;
use App\Models\LeaveRequest;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Collection;

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
            AuditService::logSystemOrFail(
                'SIMPEG Scheduler',
                'UPDATE',
                'LeaveRequest',
                $leaveRequest->id,
                [
                    'status' => $statusBefore,
                    'rollover_source_year' => null,
                    'rollover_target_year' => null,
                ],
                [
                    'status' => LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER,
                    'rollover_source_year' => $sourceYear,
                    'rollover_target_year' => $targetYear,
                    'reservation_event_id' => $release?->id,
                ],
            );
            $returnedRequestIds[] = $leaveRequest->id;
        }

        if ($returnedRequestIds === []) {
            return 0;
        }

        $detailRequestId = $returnedRequestIds[0];

        // Record in-app ikut transaksi rollover dan gagal tertutup. NotificationService
        // menjadwalkan email after-commit sehingga rollback tidak mengirim informasi keliru.
        $this->notifications->createForEmployee(
            $employee,
            'cuti.dikembalikan_karena_rollover',
            'Pengajuan Cuti Dikembalikan karena Rollover',
            "Pengajuan Cuti Tahunan tahun {$sourceYear} dikembalikan. Perbaiki tanggal dan ajukan kembali pada tahun {$targetYear}.",
            [
                'leave_request_id' => $detailRequestId,
                'leave_request_ids' => $returnedRequestIds,
                'jumlah_pengajuan' => count($returnedRequestIds),
                'reason' => 'Pengajuan dikembalikan karena rollover saldo cuti tahunan.',
                'source_year' => $sourceYear,
                'target_year' => $targetYear,
                'url' => route('cuti.index', [], false),
            ],
        );

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

        AuditService::logSystemOrFail(
            'SIMPEG Scheduler',
            'LEAVE_BALANCE_RESERVATION_RELEASED',
            LeaveBalanceReservationEvent::class,
            $event->id,
            [
                'leave_request_id' => $leaveRequest->id,
                'allocated_days' => $reserved,
                'release_context' => 'rollover_return',
            ],
            [
                'leave_request_id' => $leaveRequest->id,
                'allocated_days' => 0,
                'release_context' => 'rollover_return',
                'source_year' => $sourceYear,
                'target_year' => $targetYear,
            ],
        );

        return $event;
    }
}
