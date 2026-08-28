<?php

namespace App\Services;

use App\Jobs\SendSimpegNotificationEmailJob;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\SimpegNotification;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationRecipientResolver;
use App\Services\Notifications\WhatsApp\WhatsAppNotificationDispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection as SupportCollection;

class NotificationService
{
    public function __construct(
        private readonly NotificationRecipientResolver $recipients,
        private readonly NotificationChannelResolver $channels,
        private readonly WhatsAppNotificationDispatcher $whatsApp,
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
        $this->dispatchWhatsApp($employee, $additionalRecipients, $type, $data);

        return $notification;
    }

    /**
     * Membuat atau menyegarkan satu notifikasi in-app untuk alert EWS yang sama.
     * Pengiriman email hanya dilakukan ketika notifikasi pertama kali dibuat.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $createIfMissing  Jika false, hanya perbarui notifikasi yang sudah ada.
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
        $inAppEnabled = $this->channels->isEnabledForEvent($type, 'in_app');
        if (! $inAppEnabled) {
            // Menonaktifkan in_app sengaja ikut menghentikan email reminder karena
            // dedup reminder email berlabuh pada record in-app.
            // Namun channel WhatsApp memiliki idempotency mandiri (berbasis ews_alert_id),
            // sehingga tetap dievaluasi jika alert belum di-acknowledge.
            if ($alert->notification_acknowledged_at === null && $createIfMissing) {
                $additionalRecipients = $this->recipients->additionalRecipients($employee, $type, $data);
                $this->dispatchWhatsApp($employee, $additionalRecipients, $type, $data);
            }

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

            // Delivery WhatsApp memiliki idempotensi terpisah dari record in-app.
            // Karena itu reminder lama yang masih belum dibaca perlu dievaluasi ulang
            // pada setiap scheduler run: channel/readiness WhatsApp dapat baru aktif
            // setelah notifikasi in-app pertama kali dibuat.
            if ($createIfMissing && $alert->notification_acknowledged_at === null) {
                $additionalRecipients = $this->recipients->additionalRecipients($employee, $type, $data);
                $this->dispatchWhatsApp($employee, $additionalRecipients, $type, $data);
            }

            return $notification->refresh();
        }

        // Jika tidak ada lagi notifikasi belum dibaca, acknowledgement mencegah
        // notifikasi baru dibuat ulang setelah pegawai membacanya.
        if ($alert->notification_acknowledged_at !== null) {
            return null;
        }

        // Pegawai yang tidak memenuhi syarat tidak boleh menerima notifikasi baru,
        // tetapi notifikasi belum dibaca yang sudah ada telah diselaraskan di atas.
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

        // Fan-out email dan WhatsApp ke penerima tambahan (Admin Kepegawaian) mengikuti resolver
        // yang sama dengan createForEmployee, tetapi terbatas pada channel eksternal; admin tidak
        // dibuatkan record in-app reminder karena dedup reminder berlabuh pada pegawai.
        // Notifikasi hanya dikirim saat pengingat pertama kali dibuat supaya refresh
        // reminder tidak membanjiri antrean.
        $additionalRecipients = $this->recipients->additionalRecipients($employee, $type, $data);
        $this->dispatchEmails($employee, $additionalRecipients, $type, $title, $body, $data);
        $this->dispatchWhatsApp($employee, $additionalRecipients, $type, $data);

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
     * Menjadwalkan pengiriman WhatsApp via dispatcher bila seluruh syarat kesiapan terpenuhi.
     * Fail-closed: default nonaktif menjamin tidak ada pesan/panggilan keluar jika belum terverifikasi.
     *
     * @param  SupportCollection<int, Employee>  $additionalRecipients
     * @param  array<string, mixed>|null  $data
     */
    private function dispatchWhatsApp(
        Employee $primaryRecipient,
        SupportCollection $additionalRecipients,
        string $type,
        ?array $data,
    ): void {
        $this->whatsApp->dispatch($primaryRecipient, $type, $data);

        foreach ($additionalRecipients as $recipient) {
            if ($recipient->id !== $primaryRecipient->id) {
                $this->whatsApp->dispatch($recipient, $type, $data);
            }
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
            // Notifikasi yang sudah dibaca lebih dari 5 menit lalu tidak lagi ditampilkan di lonceng header.
            ->where(function ($query): void {
                $query->where('is_read', false)
                    ->orWhere('read_at', '>', now()->subMinutes(5));
            })
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
