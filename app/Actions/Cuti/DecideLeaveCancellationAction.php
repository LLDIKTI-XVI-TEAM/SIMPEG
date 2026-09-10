<?php

namespace App\Actions\Cuti;

use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Cuti\LeaveBalanceReservationService;
use App\Services\Cuti\LeaveUsageOverlapService;
use App\Services\NotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Memutuskan hold pembatalan secara atomik tanpa menghapus jejak request atau approval lama. */
final class DecideLeaveCancellationAction
{
    public function __construct(
        private readonly LeaveBalanceReservationService $reservations,
        private readonly LeaveUsageOverlapService $overlap,
        private readonly NotificationService $notifications,
    ) {}

    public function execute(
        LeaveCancellationRequest $cancellation,
        User $actor,
        string $decision,
        Request $request,
    ): LeaveCancellationRequest {
        $requestUser = $request->user();

        if (! $requestUser instanceof User
            || $requestUser->id !== $actor->id
            || ! $actor->hasPermission('cuti.cancellation.manage')) {
            throw new AuthorizationException('Anda tidak berwenang memutuskan permohonan pembatalan cuti.');
        }

        $decisionStatus = match ($decision) {
            'DISETUJUI' => LeaveCancellationRequest::STATUS_APPROVED,
            'DITOLAK' => LeaveCancellationRequest::STATUS_REJECTED,
            default => throw ValidationException::withMessages([
                'decision' => 'Keputusan pembatalan tidak valid.',
            ]),
        };

        $decided = DB::transaction(function () use ($cancellation, $actor, $decisionStatus, $request): LeaveCancellationRequest {
            // Urutan lock baku: request utama, pembatalan, lalu employee/saldo di service reservasi.
            $lockedRequest = LeaveRequest::query()
                ->whereKey($cancellation->leave_request_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedCancellation = LeaveCancellationRequest::query()
                ->whereKey($cancellation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCancellation->leave_request_id !== $lockedRequest->id
                || $lockedCancellation->status !== LeaveCancellationRequest::STATUS_PENDING
                || $lockedRequest->status !== LeaveRequest::STATUS_CANCELLATION_PENDING) {
                throw ValidationException::withMessages([
                    'decision' => 'Permohonan pembatalan ini sudah tidak dapat diputuskan.',
                ]);
            }

            $leaveRequestStatusBefore = $lockedRequest->status;

            $lockedCancellation->forceFill([
                'status' => $decisionStatus,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ])->save();

            if ($decisionStatus === LeaveCancellationRequest::STATUS_APPROVED) {
                $lockedRequest->steps()
                    ->whereIn('status', ['active', 'pending'])
                    ->update([
                        'status' => 'skipped',
                        'skipped_reason' => LeaveRequestStep::SKIPPED_REQUEST_CANCELLED,
                        'acted_at' => now(),
                    ]);
                $lockedRequest->forceFill(['status' => LeaveRequest::STATUS_CANCELLED])->save();
                $this->reservations->releaseForApprovedCancellation(
                    $lockedRequest,
                    $lockedCancellation,
                    $actor,
                    $request,
                );
            } else {
                // Mutex pegawai mencegah status dipulihkan bersamaan dengan proses rollover pengajuan yang sama.
                $this->overlap->lockEmployee($lockedRequest->employee_id);

                if (! in_array($lockedCancellation->resume_status, ['menunggu_approval', 'ditangguhkan'], true)) {
                    throw ValidationException::withMessages([
                        'decision' => 'Status pemulihan pembatalan tidak valid.',
                    ]);
                }

                $lockedRequest->forceFill(['status' => $lockedCancellation->resume_status])->save();
            }

            AuditService::logOrFail(
                $decisionStatus === LeaveCancellationRequest::STATUS_APPROVED
                    ? 'LEAVE_CANCELLATION_APPROVED'
                    : 'LEAVE_CANCELLATION_REJECTED',
                'LeaveCancellationRequest',
                $lockedCancellation->id,
                ['status' => LeaveCancellationRequest::STATUS_PENDING],
                [
                    'leave_request_id' => $lockedRequest->id,
                    'status' => $lockedCancellation->status,
                    'resume_status' => $lockedCancellation->resume_status,
                    'reason_recorded' => true,
                    'leave_request_status_before' => $leaveRequestStatusBefore,
                    'leave_request_status_after' => $lockedRequest->status,
                ],
                $request,
            );

            return $lockedCancellation;
        });

        $employee = $decided->leaveRequest()->with('employee')->firstOrFail()->employee;
        if ($employee !== null) {
            $approved = $decided->status === LeaveCancellationRequest::STATUS_APPROVED;
            $this->notifications->createForEmployee(
                $employee,
                $approved ? 'cuti.pembatalan_disetujui' : 'cuti.pembatalan_ditolak',
                $approved ? 'Pembatalan Cuti Disetujui' : 'Pembatalan Cuti Ditolak',
                $approved
                    ? 'Permohonan pembatalan cuti Anda telah disetujui.'
                    : 'Permohonan pembatalan cuti Anda ditolak dan approval dilanjutkan.',
                [
                    'leave_request_id' => $decided->leave_request_id,
                    'leave_cancellation_request_id' => $decided->id,
                    'url' => route('cuti.show', ['id' => $decided->leave_request_id], false),
                ],
            );
        }

        return $decided;
    }
}
