<?php

namespace App\Services\Notifications;

use Illuminate\Support\Str;

class LeaveNotificationUrl
{
    /**
     * Membentuk tujuan lokal permintaan approval tanpa membaca record atau memberi hak akses.
     * Event lain mengembalikan null agar kebijakan tujuan existing tetap digunakan.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function resolve(string $type, ?array $data): ?string
    {
        if (! in_array($type, ['cuti.pengajuan_baru', 'cuti.menunggu_persetujuan'], true)) {
            return null;
        }

        $id = $data['leave_request_id'] ?? null;

        return is_string($id) && Str::isUuid($id)
            ? route('cuti.show', $id, false)
            : route('cuti.approval', [], false);
    }
}
