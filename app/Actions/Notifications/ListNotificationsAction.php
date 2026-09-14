<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;
use App\Services\Notifications\LeaveNotificationUrl;
use App\Services\NotificationService;

class ListNotificationsAction
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly LeaveNotificationUrl $leaveNotificationUrl,
    ) {}

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
                ->map(function (SimpegNotification $notification): array {
                    $payload = $notification->toApiArray();
                    $url = $this->leaveNotificationUrl->resolve($notification->type, $notification->data);

                    // Normalisasi inbox tersimpan hanya pada respons, bukan perubahan payload historis.
                    if ($url !== null) {
                        $payload['data']['url'] = $url;
                    }

                    return $payload;
                })
                ->values(),
            'meta' => [
                'unread_count' => $this->notifications->unreadCountForEmployee($employeeId),
            ],
        ];
    }
}
