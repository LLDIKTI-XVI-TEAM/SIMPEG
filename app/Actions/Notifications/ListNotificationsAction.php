<?php

namespace App\Actions\Notifications;

use App\Services\NotificationService;

class ListNotificationsAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Mengambil inbox notifikasi milik pegawai aktif beserta jumlah belum dibaca.
     *
     * @return array{data: mixed, meta: array{unread_count: int}}
     */
    public function execute(?string $employeeId): array
    {
        return [
            'data' => $this->notifications
                ->latestForEmployee($employeeId)
                ->map(fn ($notification) => $notification->toApiArray())
                ->values(),
            'meta' => [
                'unread_count' => $this->notifications->unreadCountForEmployee($employeeId),
            ],
        ];
    }
}
