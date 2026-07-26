<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;

class PurgeReadEwsNotificationsAction
{
    public const RETENTION_MINUTES = 10;

    /**
     * Menghapus permanen notifikasi EWS yang telah dibaca selama minimal 10 menit.
     * Notifikasi EWS belum dibaca sengaja dipertahankan tanpa batas waktu.
     */
    public function execute(): int
    {
        return SimpegNotification::query()
            ->where('type', 'like', 'ews.%')
            ->where('is_read', true)
            ->whereNotNull('read_at')
            ->where('read_at', '<=', now()->subMinutes(self::RETENTION_MINUTES))
            ->delete();
    }
}
