<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;
use App\Services\Notifications\LeaveNotificationUrl;
use App\Services\NotificationService;
use Illuminate\Pagination\LengthAwarePaginator;

class PaginateNotificationsAction
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LeaveNotificationUrl $leaveNotificationUrl,
    ) {}

    /** Membatasi tujuan notifikasi ke halaman inbox milik pegawai tanpa query pengajuan per baris. */
    public function execute(?string $employeeId, int $perPage = 10): array
    {
        if ($employeeId === null) {
            return [
                'notifications' => new LengthAwarePaginator([], 0, $perPage),
                'unreadCount' => 0,
                'leaveNotificationUrls' => [],
            ];
        }

        $notifications = SimpegNotification::query()
            ->where('user_id', $employeeId)
            ->orderBy('is_read')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return [
            'notifications' => $notifications,
            'leaveNotificationUrls' => $notifications->getCollection()
                ->mapWithKeys(fn (SimpegNotification $notification): array => [
                    $notification->id => $this->leaveNotificationUrl->resolve($notification->type, $notification->data),
                ])->all(),
            'unreadCount' => $this->notifications->unreadCountForEmployee($employeeId),
        ];
    }
}
