<?php

namespace App\Services\Notifications;

use App\Models\NotificationEventChannel;
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

    /**
     * Fail-closed: delivery hanya aktif bila channel global dan pasangan kebijakan event-channel sama-sama aktif.
     */
    public function isEnabledForEvent(string $eventKey, string $channelCode): bool
    {
        return NotificationEventChannel::query()
            ->where('notification_event_channels.event_key', $eventKey)
            ->where('notification_event_channels.is_enabled', true)
            ->whereHas('channel', fn ($query) => $query
                ->where('ref_notification_channels.is_enabled', true)
                ->where('code', $channelCode))
            ->exists();
    }
}
