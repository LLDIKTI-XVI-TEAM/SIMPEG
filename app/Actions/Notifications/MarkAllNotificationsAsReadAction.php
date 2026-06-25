<?php

namespace App\Actions\Notifications;

use App\Services\NotificationService;

class MarkAllNotificationsAsReadAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Menandai semua notifikasi belum dibaca milik pegawai aktif.
     */
    public function execute(?string $employeeId): int
    {
        return $this->notifications->markAllAsReadForEmployee($employeeId);
    }
}
