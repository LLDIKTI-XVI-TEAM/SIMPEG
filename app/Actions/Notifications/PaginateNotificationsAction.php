<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;
use App\Services\NotificationService;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginateNotificationsAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function execute(?string $userId, int $perPage = 10): array
    {
        if ($userId === null) {
            return [
                'notifications' => new LengthAwarePaginator([], 0, $perPage),
                'unreadCount' => 0,
            ];
        }

        return [
            'notifications' => SimpegNotification::query()
                ->where('recipient_user_id', $userId)
                ->orderBy('is_read')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage),
            'unreadCount' => $this->notifications->unreadCountForUser($userId),
        ];
    }
}
