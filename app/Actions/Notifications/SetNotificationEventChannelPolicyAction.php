<?php

namespace App\Actions\Notifications;

use App\Models\NotificationEventChannel;
use App\Models\RefNotificationChannel;
use App\Services\AuditService;
use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetNotificationEventChannelPolicyAction
{
    public function __construct(private readonly NotificationEventCatalog $catalog) {}

    public function execute(string $channelId, string $eventKey, bool $isEnabled, Request $request): NotificationEventChannel
    {
        return DB::transaction(function () use ($channelId, $eventKey, $isEnabled, $request): NotificationEventChannel {
            // Lock master menyerialkan create awal untuk pasangan pada channel yang sama;
            // unique constraint database tetap menjadi pagar terakhir terhadap duplikasi.
            $channel = RefNotificationChannel::query()->lockForUpdate()->findOrFail($channelId);

            if (! $this->catalog->supportsChannel($eventKey, $channel->code)) {
                throw ValidationException::withMessages([
                    'event_key' => 'Event tidak mendukung channel notifikasi yang dipilih.',
                ]);
            }

            $policy = NotificationEventChannel::query()
                ->where('event_key', $eventKey)
                ->where('notification_channel_id', $channel->id)
                ->lockForUpdate()
                ->first();

            if ($policy === null) {
                $oldValues = null;
                $policy = new NotificationEventChannel;
            } else {
                if ($policy->is_enabled === $isEnabled) {
                    return $policy;
                }

                $oldValues = $this->auditValues($policy);
            }

            $policy->forceFill([
                'event_key' => $eventKey,
                'notification_channel_id' => $channel->id,
                'is_enabled' => $isEnabled,
            ])->save();

            AuditService::logOrFail(
                'CONFIG_UPDATE',
                'NotificationEventChannel',
                $policy->id,
                $oldValues,
                $this->auditValues($policy),
                $request,
            );

            return $policy;
        });
    }

    /** @return array{event_key: string, notification_channel_id: string, is_enabled: bool} */
    private function auditValues(NotificationEventChannel $policy): array
    {
        return [
            'event_key' => $policy->event_key,
            'notification_channel_id' => $policy->notification_channel_id,
            'is_enabled' => $policy->is_enabled,
        ];
    }
}
