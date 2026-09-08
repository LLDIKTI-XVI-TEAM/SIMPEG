<?php

namespace App\Actions\Notifications;

use App\Services\NotificationService;

class MarkAllNotificationsAsReadAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Menandai semua notifikasi belum dibaca milik User penerima.
     */
    public function execute(?string $userId): int
    {
        return $this->notifications->markAllAsReadForUser($userId);
    }
}
