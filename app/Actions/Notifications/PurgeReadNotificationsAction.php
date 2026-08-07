<?php

namespace App\Actions\Notifications;

use App\Models\SimpegNotification;

class PurgeReadNotificationsAction
{
    public const RETENTION_MINUTES = 0;

    /**
     * Menghapus permanen seluruh notifikasi (EWS, Cuti, Impor Pegawai, dll) yang telah dibaca.
     * Kebijakan retensi global: notifikasi dihapus segera setelah dibaca untuk efisiensi storage.
     * Notifikasi belum dibaca dipertahankan tanpa batas waktu sampai user membacanya.
     */
    public function execute(): int
    {
        $query = SimpegNotification::query()
            ->where('is_read', true)
            ->whereNotNull('read_at');

        // Hanya filter waktu jika retention period > 0
        if (self::RETENTION_MINUTES > 0) {
            $query->where('read_at', '<=', now()->subMinutes(self::RETENTION_MINUTES));
        }

        return $query->delete();
    }
}
