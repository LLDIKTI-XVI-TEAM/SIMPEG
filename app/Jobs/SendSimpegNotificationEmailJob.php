<?php

namespace App\Jobs;

use App\Mail\SimpegNotificationMail;
use App\Models\Employee;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveApprovalService;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\NotificationRecipientResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendSimpegNotificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Menyimpan data minimum untuk email agar job tidak membawa model pegawai lengkap ke queue.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $employeeId,
        public readonly string $eventKey,
        public readonly string $title,
        public readonly string $body,
        private readonly ?array $data = null,
    ) {}

    /**
     * Mengirim email ke alamat pegawai bila tersedia; pegawai tanpa email dilewati aman.
     */
    public function handle(
        NotificationEventCatalog $catalog,
        NotificationChannelResolver $channels,
        NotificationRecipientResolver $recipients,
    ): void {
        // Job tertunda harus menghormati kill-switch terbaru, bukan snapshot kebijakan saat enqueue.
        if (! $catalog->supportsChannel($this->eventKey, 'email')
            || ! $channels->isEnabledForEvent($this->eventKey, 'email')) {
            return;
        }

        // Persetujuan yang menunggu delivery tidak lagi berlaku setelah penangguhan administratif.
        if ($this->eventKey === 'cuti.disetujui'
            && ! LeaveRequest::query()
                ->whereKey($this->data['leave_request_id'] ?? null)
                ->where('status', 'disetujui')
                ->exists()) {
            return;
        }

        // Status dapat tetap sama setelah revisi; hanya versi dan tahap milik approver aktif yang boleh meminta tindakan.
        if (in_array($this->eventKey, ['cuti.pengajuan_baru', 'cuti.menunggu_persetujuan'], true)
            && ! LeaveRequest::query()
                ->whereKey($this->data['leave_request_id'] ?? null)
                ->where('revision_version', $this->data['leave_request_version'] ?? null)
                ->whereIn('status', LeaveApprovalService::ACTIONABLE_STATUSES)
                ->whereHas('steps', fn (Builder $query) => $query
                    ->whereKey($this->data['leave_request_step_id'] ?? null)
                    ->where('status', 'active')
                    ->where('approver_employee_id', $this->employeeId))
                ->exists()) {
            return;
        }

        // Izin, binding, scope, dan status workflow dapat berubah setelah email masuk antrean.
        if ($this->eventKey === 'cuti.pembatalan_diajukan') {
            $cancellation = LeaveCancellationRequest::query()
                ->with('leaveRequest:id,employee_id,status')
                ->whereKey($this->data['leave_cancellation_request_id'] ?? null)
                ->where('leave_request_id', $this->data['leave_request_id'] ?? null)
                ->where('status', LeaveCancellationRequest::STATUS_PENDING)
                ->first(['id', 'leave_request_id']);
            $leave = $cancellation?->leaveRequest;

            if ($leave === null || $leave->status !== LeaveRequest::STATUS_CANCELLATION_PENDING) {
                return;
            }

            // Email ditujukan ke Employee; mapping tunggal diperlukan untuk memeriksa otorisasi akun penerima.
            $recipientUsers = User::query()->where('employee_id', $this->employeeId)->limit(2)->get(['id']);
            if ($recipientUsers->count() !== 1
                || ! $recipients->canReceiveCancellationDecision($recipientUsers->sole(), $leave)) {
                return;
            }
        }

        $employee = Employee::find($this->employeeId);

        $email = $employee?->email_pribadi ?? $employee?->email;

        if ($employee === null || $email === null || $email === '') {
            return;
        }

        Mail::to($email)->send(new SimpegNotificationMail(
            $this->title,
            $this->body,
            $this->data,
        ));
    }

    /**
     * Mencatat kegagalan final tanpa membocorkan detail kredensial SMTP dari exception message.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Email notifikasi SIMPEG gagal dikirim setelah retry maksimum.', [
            'employee_id' => $this->employeeId,
            'title' => $this->title,
            'exception' => $exception::class,
        ]);
    }
}
