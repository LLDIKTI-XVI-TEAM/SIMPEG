<?php

namespace App\Actions\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\AuditService;
use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetNotificationChannelEnabledAction
{
    public function __construct(private readonly NotificationEventCatalog $catalog) {}

    public function execute(string $channelId, bool $isEnabled, Request $request): RefNotificationChannel
    {
        return DB::transaction(function () use ($channelId, $isEnabled, $request): RefNotificationChannel {
            // Lock pada baris master mencegah dua desired-state paralel saling menimpa.
            $channel = RefNotificationChannel::query()->lockForUpdate()->findOrFail($channelId);

            if ($isEnabled && ! $this->catalog->hasAdapter($channel->code)) {
                throw ValidationException::withMessages([
                    'is_enabled' => 'Channel belum memiliki adapter runtime dan tidak dapat diaktifkan.',
                ]);
            }

            if ($channel->is_enabled === $isEnabled) {
                return $channel;
            }

            $oldState = $channel->is_enabled;
            $channel->forceFill(['is_enabled' => $isEnabled])->save();

            AuditService::logOrFail(
                'CONFIG_UPDATE',
                'RefNotificationChannel',
                $channel->id,
                ['is_enabled' => $oldState],
                ['is_enabled' => $isEnabled],
                $request,
            );

            return $channel;
        });
    }
}
