<?php

namespace App\Actions\Notifications;

use App\Services\NotificationService;

class GetUnreadNotificationCountAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Menghitung notifikasi belum dibaca milik pegawai aktif saja.
     */
    public function execute(?string $employeeId): int
    {
        return $this->notifications->unreadCountForEmployee($employeeId);
    }
}
