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
     * Membuat atau menyegarkan satu notifikasi in-app untuk alert EWS yang sama.
     * Pengiriman email hanya dilakukan ketika notifikasi pertama kali dibuat.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $createIfMissing  Jika false, hanya update notifikasi yang sudah ada (tidak buat baru)
     */
    public function upsertEwsReminder(
        Employee $employee,
        EwsAlert $alert,
        string $type,
        string $title,
        string $body,
        array $data,
        bool $createIfMissing = true,
    ): ?SimpegNotification {
        // Kebijakan channel dicek per event (fail-closed), bukan hanya channel global,
        // agar operator bisa mematikan reminder untuk satu jenis event EWS tanpa
        // mematikan seluruh notifikasi in-app. Menonaktifkan in_app untuk event ini
        // sengaja ikut menghentikan email reminder: dedup reminder berlabuh pada
        // record in-app, sehingga tanpa record tersebut email akan terkirim ulang
        // setiap run scheduler.
        if (! $this->channels->isEnabledForEvent($type, 'in_app')) {
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

            // Notifikasi yang benar-benar belum dibaca adalah sumber kebenaran.
            // Ini memperbaiki data lama yang sudah memiliki acknowledgement, tetapi
            // notifikasinya masih belum dibaca, agar pengingat tetap diperbarui.
            if ($alert->notification_acknowledged_at !== null) {
                $alert->forceFill(['notification_acknowledged_at' => null])->save();
            }

            $notification->fill($attributes)->save();

            return $notification->refresh();
        }

        // Jika tidak ada lagi notifikasi belum dibaca, acknowledgement mencegah
        // notifikasi baru dibuat ulang setelah pegawai membacanya.
        if ($alert->notification_acknowledged_at !== null) {
            return null;
        }

        // If createIfMissing=false, don't create new notification (ineligible case)
        // This prevents sending notification to ineligible employees
        if (! $createIfMissing) {
            return null;
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

        // Fan-out email ke penerima tambahan (Admin Kepegawaian) mengikuti resolver
        // yang sama dengan createForEmployee, tetapi terbatas pada email; admin tidak
        // dibuatkan record in-app reminder karena dedup reminder berlabuh pada pegawai.
        // Email hanya dikirim saat notifikasi pertama kali dibuat supaya refresh
        // reminder tidak membanjiri email.
        $additionalRecipients = $this->recipients->additionalRecipients($employee, $type, $data);
        $this->dispatchEmails($employee, $additionalRecipients, $type, $title, $body, $data);

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
        if (! $this->recipients->emailEnabled($type, $data)) {
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
            SendSimpegNotificationEmailJob::dispatch($recipient->id, $type, $title, $body, $data)->afterCommit();
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

        // Samakan dengan jalur single-read: notifikasi lama (legacy) menyimpan ID alert
        // di payload JSON data, bukan di kolom ews_alert_id. Keduanya harus diakui agar
        // reminder EWS tidak muncul kembali setelah pengguna menandai semua terbaca.
        $alertIds = $notifications
            ->map(fn (SimpegNotification $notification): mixed => $notification->ews_alert_id
                ?? $notification->data['ews_alert_id']
                ?? null)
            ->filter(fn (mixed $alertId): bool => is_string($alertId) && $alertId !== '')
            ->unique()
            ->values();

        if ($alertIds->isNotEmpty()) {
            EwsAlert::query()
                ->whereIn('id', $alertIds)
                ->whereNull('notification_acknowledged_at')
                ->update(['notification_acknowledged_at' => $now]);
        }

        return $updated;
    }
}
