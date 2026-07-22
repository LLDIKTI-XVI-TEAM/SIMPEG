<?php

namespace App\Services;

use App\Jobs\SendSimpegNotificationEmailJob;
use App\Models\Employee;
use App\Models\SimpegNotification;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationRecipientResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class NotificationService
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationChannelResolver $channels,
    ) {}

    /**
     * Membuat notifikasi in-app untuk satu pegawai penerima dan menjadwalkan email bila channel-nya aktif.
     * Email dikirim lewat queue agar request utama tidak menunggu SMTP.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function createForEmployee(Employee $employee, string $type, string $title, string $body, ?array $data = null): ?SimpegNotification
    {
        $notification = null;
        $additionalRecipients = $this->recipients->additionalRecipients($employee, $type, $data);

        if ($this->channels->isEnabledForEvent($type, 'in_app')) {
            // EWS rutin perlu terlihat oleh pegawai dan Admin Kepegawaian tanpa menggandakan pegawai yang juga berperan admin.
            $inAppRecipients = collect([$employee])
                ->merge($additionalRecipients)
                ->unique('id')
                ->values();

            foreach ($inAppRecipients as $recipient) {
                $created = SimpegNotification::create([
                    'user_id' => $recipient->id,
                    'type' => $type,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                ]);

                if ($recipient->id === $employee->id) {
                    $notification = $created;
                }
            }
        }

        $this->dispatchEmails($employee, $additionalRecipients, $type, $title, $body, $data);

        return $notification;
    }

    /**
     * Menjadwalkan email untuk event yang aktif channel email tanpa mengubah kontrak in-app notification.
     *
     * @param  SupportCollection<int, Employee>  $additionalRecipients
     * @param  array<string, mixed>|null  $data
     */
    private function dispatchEmails(
        Employee $primaryRecipient,
        SupportCollection $additionalRecipients,
        string $type,
        string $title,
        string $body,
        ?array $data,
    ): void {
        if (! $this->recipients->emailEnabled($type)) {
            return;
        }

        $emailRecipients = collect();

        if ($this->recipients->shouldEmailPrimaryRecipient($type, $data)) {
            $emailRecipients->push($primaryRecipient);
        }

        $emailRecipients = $emailRecipients
            ->merge($additionalRecipients)
            ->filter(fn (Employee $employee): bool => $employee->email !== null && $employee->email !== '')
            ->unique('id')
            ->values();

        foreach ($emailRecipients as $recipient) {
            SendSimpegNotificationEmailJob::dispatch($recipient->id, $title, $body, $data)->afterCommit();
        }
    }

    /**
     * @return Collection<int, SimpegNotification>
     */
    public function latestForEmployee(?string $employeeId, int $limit = 10): Collection
    {
        if ($employeeId === null) {
            return new Collection;
        }

        return SimpegNotification::query()
            ->where('user_id', $employeeId)
            // UUID bersifat acak; urutan kedua hanya untuk stabilitas saat created_at sama, bukan kronologi mutlak.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function unreadCountForEmployee(?string $employeeId): int
    {
        if ($employeeId === null) {
            return 0;
        }

        return SimpegNotification::query()
            ->where('user_id', $employeeId)
            ->unread()
            ->count();
    }

    public function markAsReadForEmployee(string $notificationId, ?string $employeeId): ?SimpegNotification
    {
        if ($employeeId === null) {
            return null;
        }

        $notification = SimpegNotification::query()
            ->where('id', $notificationId)
            ->where('user_id', $employeeId)
            ->first();

        if ($notification === null) {
            return null;
        }

        if (! $notification->is_read) {
            $notification->forceFill([
                'is_read' => true,
                'read_at' => now(),
            ])->save();
        }

        return $notification->refresh();
    }

    public function markAllAsReadForEmployee(?string $employeeId): int
    {
        if ($employeeId === null) {
            return 0;
        }

        return SimpegNotification::query()
            ->where('user_id', $employeeId)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
