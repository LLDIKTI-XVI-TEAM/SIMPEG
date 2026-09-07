<?php

namespace App\Actions\Cuti;

use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Menahan workflow cuti milik pemohon sambil mempertahankan snapshot dan reservasi yang sudah ada. */
class RequestLeaveCancellationAction
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationService $notifications,
    ) {}

    public function execute(LeaveRequest $leaveRequest, User $actor, string $reason, Request $request): LeaveCancellationRequest
    {
        try {
            $cancellation = DB::transaction(function () use ($leaveRequest, $actor, $reason, $request): LeaveCancellationRequest {
                // Request utama selalu dikunci lebih dahulu agar pembatalan tidak berlomba dengan keputusan approver.
                $locked = LeaveRequest::query()->whereKey($leaveRequest->id)->lockForUpdate()->firstOrFail();

                if ($actor->employee_id === null || $actor->employee_id !== $locked->employee_id) {
                    abort(403, 'Anda tidak berwenang meminta pembatalan pengajuan cuti ini.');
                }

                if (! in_array($locked->status, ['menunggu_approval', 'ditangguhkan'], true)) {
                    throw ValidationException::withMessages([
                        'status' => 'Pengajuan cuti ini tidak dapat diminta pembatalannya.',
                    ]);
                }

                if (LeaveCancellationRequest::query()
                    ->where('leave_request_id', $locked->id)
                    ->where('status', LeaveCancellationRequest::STATUS_PENDING)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'status' => 'Permohonan pembatalan untuk pengajuan cuti ini masih menunggu keputusan.',
                    ]);
                }

                $cancellation = LeaveCancellationRequest::create([
                    'leave_request_id' => $locked->id,
                    'requested_by' => $actor->id,
                    'reason' => $reason,
                    'status' => LeaveCancellationRequest::STATUS_PENDING,
                    'resume_status' => $locked->status,
                ]);
                $previousStatus = $locked->status;
                $locked->forceFill(['status' => LeaveRequest::STATUS_CANCELLATION_PENDING])->save();

                // Alasan hanya hidup pada record pembatalan; audit cukup membuktikan bahwa alasan telah direkam.
                AuditService::logOrFail(
                    'LEAVE_CANCELLATION_REQUESTED',
                    'LeaveCancellationRequest',
                    $cancellation->id,
                    null,
                    [
                        'leave_request_id' => $locked->id,
                        'requested_by' => $actor->id,
                        'status' => $cancellation->status,
                        'resume_status' => $cancellation->resume_status,
                        'reason_recorded' => true,
                        'leave_request_status_before' => $previousStatus,
                        'leave_request_status_after' => $locked->status,
                    ],
                    $request,
                );

                return $cancellation;
            });
        } catch (QueryException $exception) {
            if ($this->isPendingCancellationUniqueViolation($exception)) {
                throw ValidationException::withMessages([
                    'status' => 'Permohonan pembatalan untuk pengajuan cuti ini masih menunggu keputusan.',
                ]);
            }

            throw $exception;
        }

        // Query penerima dan pengiriman dikerjakan setelah commit agar notifikasi tidak merujuk workflow yang rollback.
        foreach ($this->recipients->cancellationDecisionRecipients() as $recipient) {
            $this->notifications->createForEmployee(
                $recipient,
                'cuti.pembatalan_diajukan',
                'Permohonan Pembatalan Cuti Baru',
                'Terdapat permohonan pembatalan cuti yang menunggu keputusan Anda.',
                [
                    'leave_request_id' => $leaveRequest->id,
                    'leave_cancellation_request_id' => $cancellation->id,
                    'url' => route('cuti.cancellations.index', [], false),
                ],
            );
        }

        return $cancellation;
    }

    /** Hanya pelanggaran index partial pembatalan yang diterjemahkan menjadi error domain stabil. */
    private function isPendingCancellationUniqueViolation(QueryException $exception): bool
    {
        return $exception->getCode() === '23505'
            && str_contains($exception->getMessage(), 'leave_cancellation_requests_pending_request_unique');
    }
}
