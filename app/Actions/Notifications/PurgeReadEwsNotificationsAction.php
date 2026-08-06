<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;

class PurgeReadEwsNotificationsAction
{
    public const RETENTION_MINUTES = 5;

    /**
     * Menghapus permanen seluruh notifikasi (EWS, Cuti, Impor Pegawai, dll) yang telah dibaca selama minimal 5 menit.
     * Notifikasi belum dibaca sengaja dipertahankan tanpa batas waktu.
     */
    public function execute(): int
    {
        return SimpegNotification::query()
            ->where('is_read', true)
            ->whereNotNull('read_at')
            ->where('read_at', '<=', now()->subMinutes(self::RETENTION_MINUTES))
            ->delete();
    }
}
