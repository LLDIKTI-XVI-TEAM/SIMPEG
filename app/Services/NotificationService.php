<?php

namespace App\Services;

use App\Jobs\SendSimpegNotificationEmailJob;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\SimpegNotification;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationRecipientResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;

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

        if ($this->channels->isEnabled('in_app')) {
            $notification = SimpegNotification::create([
                'user_id' => $employee->id,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        }

        $this->dispatchEmails($employee, $type, $title, $body, $data);

        return $notification;
    }

    /**
     * Membuat atau menyegarkan satu notifikasi in-app untuk alert EWS yang sama.
     * Pengiriman email hanya dilakukan ketika notifikasi pertama kali dibuat.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsertEwsReminder(
        Employee $employee,
        EwsAlert $alert,
        string $type,
        string $title,
        string $body,
        array $data,
    ): ?SimpegNotification {
        if ($alert->notification_acknowledged_at !== null || ! $this->channels->isEnabled('in_app')) {
            return null;
        }

        $attributes = [
            'ews_alert_id' => $alert->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];

        $notification = SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->where(function ($query) use ($alert): void {
                $query->where('ews_alert_id', $alert->id)
                    ->orWhere('data->ews_alert_id', $alert->id);
            })
            ->first();

        if ($notification !== null) {
            if ($notification->is_read) {
                $alert->forceFill(['notification_acknowledged_at' => $notification->read_at ?? now()])->save();

                return $notification;
            }

            $notification->fill($attributes)->save();

            return $notification->refresh();
        }

        try {
            $notification = SimpegNotification::create([
                'user_id' => $employee->id,
                'ews_alert_id' => $alert->id,
                ...$attributes,
            ]);
        } catch (QueryException) {
            return SimpegNotification::query()
                ->where('user_id', $employee->id)
                ->where('ews_alert_id', $alert->id)
                ->first();
        }

        $this->dispatchEmails($employee, $type, $title, $body, $data);

        return $notification;
    }

    /**
     * Menandai pengakuan pegawai terhadap alert EWS sehingga reminder tidak dikirim ulang.
     */
    private function acknowledgeEwsReminder(SimpegNotification $notification): void
    {
        $alertId = $notification->ews_alert_id ?? $notification->data['ews_alert_id'] ?? null;
        if (! is_string($alertId) || $alertId === '') {
            return;
        }

        EwsAlert::query()
            ->whereKey($alertId)
            ->whereNull('notification_acknowledged_at')
            ->update(['notification_acknowledged_at' => now()]);
    }

    /**
     * Menjadwalkan email untuk event yang aktif channel email tanpa mengubah kontrak in-app notification.
     *
     * @param  array<string, mixed>|null  $data
     */
    private function dispatchEmails(Employee $primaryRecipient, string $type, string $title, string $body, ?array $data): void
    {
        if (! $this->recipients->emailEnabled($type)) {
            return;
        }

        $emailRecipients = collect();

        if ($this->recipients->shouldEmailPrimaryRecipient($type, $data)) {
            $emailRecipients->push($primaryRecipient);
        }

        $emailRecipients = $emailRecipients
            ->merge($this->recipients->additionalRecipients($primaryRecipient, $type, $data))
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
            // Notifikasi belum dibaca tetap berada di atas, termasuk EWS yang sudah melewati targetnya.
            ->orderBy('is_read')
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
            $this->acknowledgeEwsReminder($notification);
        }

        return $notification->refresh();
    }

    public function markAllAsReadForEmployee(?string $employeeId): int
    {
        if ($employeeId === null) {
            return 0;
        }

        $notifications = SimpegNotification::query()
            ->where('user_id', $employeeId)
            ->unread()
            ->get();

        $now = now();
        $updated = SimpegNotification::query()
            ->whereKey($notifications->modelKeys())
            ->update([
                'is_read' => true,
                'read_at' => $now,
                'updated_at' => $now,
            ]);

        EwsAlert::query()
            ->whereIn('id', $notifications->pluck('ews_alert_id')->filter())
            ->whereNull('notification_acknowledged_at')
            ->update(['notification_acknowledged_at' => $now]);

        return $updated;
    }
}
