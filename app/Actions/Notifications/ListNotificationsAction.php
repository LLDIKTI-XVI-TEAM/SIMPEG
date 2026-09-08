<?php

namespace App\Actions\Notifications;

use App\Services\NotificationService;

class ListNotificationsAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Mengambil inbox notifikasi milik User penerima beserta jumlah belum dibaca.
     *
     * @return array{data: mixed, meta: array{unread_count: int}}
     */
    public function execute(?string $userId): array
    {
        return [
            'data' => $this->notifications
                ->latestForUser($userId)
                ->map(fn ($notification) => $notification->toApiArray())
                ->values(),
            'meta' => [
                'unread_count' => $this->notifications->unreadCountForUser($userId),
            ],
        ];
    }
}
