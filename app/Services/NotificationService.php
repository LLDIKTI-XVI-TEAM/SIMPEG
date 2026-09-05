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
use Illuminate\Support\Facades\DB;

class NotificationService
{
    /**
     * @var array<string, bool>
     */
    private array $eventChannelState = [];

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

        if ($this->isChannelEnabledForEvent($type, 'in_app')) {
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
        $inAppEnabled = $this->isChannelEnabledForEvent($type, 'in_app');
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
     * Memoisasi keputusan channel selama satu workflow layanan. Scheduler EWS dapat
     * menerbitkan banyak reminder dengan tipe yang sama dalam satu run; membaca
     * policy database untuk setiap reminder hanya menambah query tanpa mengubah
     * hasil karena perubahan konfigurasi diproses pada request/transaction lain.
     */
    private function isChannelEnabledForEvent(string $eventKey, string $channelCode): bool
    {
        $cacheKey = $eventKey.'|'.$channelCode;

        return $this->eventChannelState[$cacheKey]
            ??= $this->channels->isEnabledForEvent($eventKey, $channelCode);
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

        return DB::transaction(function () use ($notificationId, $employeeId): ?SimpegNotification {
            $snapshot = SimpegNotification::query()
                ->where('id', $notificationId)
                ->where('user_id', $employeeId)
                ->first();

            if ($snapshot === null) {
                return null;
            }

            $snapshotAlertId = $this->ewsAlertId($snapshot);
            $lockedAlert = $snapshotAlertId === null
                ? null
                : EwsAlert::query()->whereKey($snapshotAlertId)->lockForUpdate()->first();

            // Engine selalu mengunci alert sebelum menyegarkan notifikasi. Jalur baca
            // mengikuti urutan yang sama agar transaksi audit simulasi tidak membentuk
            // siklus notification -> alert terhadap scheduler.
            $notification = SimpegNotification::query()
                ->where('id', $notificationId)
                ->where('user_id', $employeeId)
                ->lockForUpdate()
                ->first();

            if ($notification === null || $this->ewsAlertId($notification) !== $snapshotAlertId) {
                return null;
            }

            if (! $notification->is_read) {
                $notification->forceFill([
                    'is_read' => true,
                    'read_at' => now(),
                ])->save();

                if ($lockedAlert !== null && $lockedAlert->notification_acknowledged_at === null) {
                    $lockedAlert->forceFill(['notification_acknowledged_at' => now()])->save();
                }
            }

            return $notification->refresh();
        });
    }

    public function markAllAsReadForEmployee(?string $employeeId): int
    {
        if ($employeeId === null) {
            return 0;
        }

        return DB::transaction(function () use ($employeeId): int {
            $snapshots = SimpegNotification::query()
                ->where('user_id', $employeeId)
                ->unread()
                ->get();
            $snapshotAlertIds = $snapshots
                ->mapWithKeys(fn (SimpegNotification $notification): array => [
                    $notification->id => $this->ewsAlertId($notification),
                ]);
            $alertIds = $snapshotAlertIds->filter()->unique()->sort()->values();

            // Bulk-read memakai urutan alert ID lalu notification ID yang deterministik,
            // sama dengan single-read dan scheduler EWS.
            $lockedAlerts = EwsAlert::query()
                ->whereIn('id', $alertIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $notifications = SimpegNotification::query()
                ->whereKey($snapshots->modelKeys())
                ->where('user_id', $employeeId)
                ->unread()
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (SimpegNotification $notification): bool => $this->ewsAlertId($notification) === $snapshotAlertIds->get($notification->id));

            $now = now();
            $updated = SimpegNotification::query()
                ->whereKey($notifications->modelKeys())
                ->update([
                    'is_read' => true,
                    'read_at' => $now,
                    'updated_at' => $now,
                ]);

            // Notifikasi lama menyimpan ID alert hanya di payload JSON; exact ID yang
            // dibekukan sebelum lock tetap dipakai untuk acknowledgement keduanya.
            foreach ($notifications as $notification) {
                $alertId = $this->ewsAlertId($notification);
                $alert = $alertId === null ? null : $lockedAlerts->get($alertId);
                if ($alert instanceof EwsAlert && $alert->notification_acknowledged_at === null) {
                    $alert->forceFill(['notification_acknowledged_at' => $now])->save();
                }
            }

            return $updated;
        });
    }

    /** Ambil identitas alert durable dari kolom baru atau payload legacy. */
    private function ewsAlertId(SimpegNotification $notification): ?string
    {
        $alertId = $notification->ews_alert_id ?? $notification->data['ews_alert_id'] ?? null;

        return is_string($alertId) && $alertId !== '' ? $alertId : null;
    }
}
