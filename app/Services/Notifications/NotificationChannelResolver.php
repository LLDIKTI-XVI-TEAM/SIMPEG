<?php

namespace App\Services\Notifications;

use App\Models\RefNotificationChannel;

class NotificationChannelResolver
{
    /**
     * Keputusan channel berada di reference table agar domain tidak perlu diubah saat operator menyalakan atau mematikan delivery.
     */
    public function isEnabled(string $channelCode): bool
    {
        return RefNotificationChannel::query()
            ->enabled()
            ->where('code', $channelCode)
            ->exists();
    }
}
