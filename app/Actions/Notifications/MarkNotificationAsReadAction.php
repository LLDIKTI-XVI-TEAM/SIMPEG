<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;
use App\Services\NotificationService;

class MarkNotificationAsReadAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Menandai satu notifikasi sebagai dibaca hanya jika notifikasi milik pegawai aktif.
     */
    public function execute(string $notificationId, ?string $employeeId): ?SimpegNotification
    {
        return $this->notifications->markAsReadForEmployee($notificationId, $employeeId);
    }
}
