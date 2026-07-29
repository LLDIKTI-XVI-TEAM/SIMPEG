<?php

namespace App\Actions\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CreateNotificationChannelAction
{
    /** @param array{code: string, name: string} $data */
    public function execute(array $data, Request $request): RefNotificationChannel
    {
        return DB::transaction(function () use ($data, $request): RefNotificationChannel {
            $channel = new RefNotificationChannel;
            $channel->forceFill([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_enabled' => false,
                'config' => null,
            ])->save();

            AuditService::logOrFail(
                'CREATE',
                'RefNotificationChannel',
                $channel->id,
                null,
                $this->auditValues($channel),
                $request,
            );

            return $channel;
        });
    }

    /** @return array{code: string, name: string, is_enabled: bool} */
    private function auditValues(RefNotificationChannel $channel): array
    {
        return [
            'code' => $channel->code,
            'name' => $channel->name,
            'is_enabled' => $channel->is_enabled,
        ];
    }
}
