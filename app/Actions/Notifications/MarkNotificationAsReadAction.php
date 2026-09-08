<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;
use App\Services\NotificationService;

class MarkNotificationAsReadAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Menandai satu notifikasi sebagai dibaca hanya jika milik User penerima.
     */
    public function execute(string $notificationId, ?string $userId): ?SimpegNotification
    {
        return $this->notifications->markAsReadForUser($notificationId, $userId);
    }
}
