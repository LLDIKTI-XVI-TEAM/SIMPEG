<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\SimpegNotification;
use Illuminate\Database\Eloquent\Collection;

class NotificationService
{
    /**
     * Membuat notifikasi in-app untuk satu pegawai penerima.
     * Email/queue sengaja belum dikirim di Sprint 1 agar channel tidak tercampur.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function createForEmployee(Employee $employee, string $type, string $title, string $body, ?array $data = null): SimpegNotification
    {
        return SimpegNotification::create([
            'user_id' => $employee->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    /**
     * @return Collection<int, SimpegNotification>
     */
    public function latestForEmployee(?string $employeeId, int $limit = 10): Collection
    {
        if ($employeeId === null) {
            return new Collection;
        }

        return SimpegNotification::query()
            ->where('user_id', $employeeId)
            // UUID bersifat acak; urutan kedua hanya untuk stabilitas saat created_at sama, bukan kronologi mutlak.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function unreadCountForEmployee(?string $employeeId): int
    {
        if ($employeeId === null) {
            return 0;
        }

        return SimpegNotification::query()
            ->where('user_id', $employeeId)
            ->unread()
            ->count();
    }

    public function markAsReadForEmployee(string $notificationId, ?string $employeeId): ?SimpegNotification
    {
        if ($employeeId === null) {
            return null;
        }

        $notification = SimpegNotification::query()
            ->where('id', $notificationId)
            ->where('user_id', $employeeId)
            ->first();

        if ($notification === null) {
            return null;
        }

        if (! $notification->is_read) {
            $notification->forceFill([
                'is_read' => true,
                'read_at' => now(),
            ])->save();
        }

        return $notification->refresh();
    }

    public function markAllAsReadForEmployee(?string $employeeId): int
    {
        if ($employeeId === null) {
            return 0;
        }

        return SimpegNotification::query()
            ->where('user_id', $employeeId)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
