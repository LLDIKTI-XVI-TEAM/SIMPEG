<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;
use App\Services\NotificationService;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginateNotificationsAction
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function execute(?string $employeeId, int $perPage = 10): array
    {
        if ($employeeId === null) {
            return [
                'notifications' => new LengthAwarePaginator([], 0, $perPage),
                'unreadCount' => 0,
            ];
        }

        return [
            'notifications' => SimpegNotification::query()
                ->where('user_id', $employeeId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($perPage),
            'unreadCount' => $this->notifications->unreadCountForEmployee($employeeId),
        ];
    }
}
