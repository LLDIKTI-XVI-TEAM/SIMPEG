<?php

namespace App\Jobs;

use App\Mail\SimpegNotificationMail;
use App\Models\Employee;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
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
