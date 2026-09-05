<?php

namespace App\Jobs;

use App\Mail\SimpegNotificationMail;
use App\Models\Employee;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Services\LeaveApprovalService;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationEventCatalog;
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
    ): void {
        // Job tertunda harus menghormati kill-switch terbaru, bukan snapshot kebijakan saat enqueue.
        if (! $catalog->supportsChannel($this->eventKey, 'email')
            || ! $channels->isEnabledForEvent($this->eventKey, 'email')) {
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

        // Admin lain mungkin sudah memutus permohonan sebelum email dalam antrean sempat dikirim.
        if ($this->eventKey === 'cuti.pembatalan_diajukan'
            && ! LeaveCancellationRequest::query()
                ->whereKey($this->data['leave_cancellation_request_id'] ?? null)
                ->where('leave_request_id', $this->data['leave_request_id'] ?? null)
                ->where('status', LeaveCancellationRequest::STATUS_PENDING)
                ->exists()) {
            return;
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
