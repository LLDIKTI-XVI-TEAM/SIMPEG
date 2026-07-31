<?php

namespace App\Actions\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UpdateNotificationChannelAction
{
    public function execute(string $channelId, string $name, Request $request): RefNotificationChannel
    {
        return DB::transaction(function () use ($channelId, $name, $request): RefNotificationChannel {
            $channel = RefNotificationChannel::query()->lockForUpdate()->findOrFail($channelId);
            $oldValues = ['name' => $channel->name];

            $channel->forceFill(['name' => $name])->save();

            AuditService::logOrFail(
                'UPDATE',
                'RefNotificationChannel',
                $channel->id,
                $oldValues,
                ['name' => $channel->name],
                $request,
            );

            return $channel;
        });
    }
}
