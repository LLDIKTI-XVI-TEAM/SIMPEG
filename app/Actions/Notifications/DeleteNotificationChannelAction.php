<?php

namespace App\Actions\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteNotificationChannelAction
{
    /** @var list<string> */
    private const CORE_CHANNEL_CODES = ['in_app', 'email', 'whatsapp_business'];

    public function execute(string $channelId, Request $request): void
    {
        DB::transaction(function () use ($channelId, $request): void {
            $channel = RefNotificationChannel::query()->lockForUpdate()->findOrFail($channelId);

            if (in_array($channel->code, self::CORE_CHANNEL_CODES, true)) {
                throw ValidationException::withMessages([
                    'channel' => 'Channel inti sistem tidak dapat dihapus.',
                ]);
            }

            if ($channel->eventPolicies()->exists()) {
                throw ValidationException::withMessages([
                    'channel' => 'Channel tidak dapat dihapus karena masih dirujuk kebijakan event.',
                ]);
            }

            $oldValues = [
                'code' => $channel->code,
                'name' => $channel->name,
                'is_enabled' => $channel->is_enabled,
            ];
            $channel->delete();

            AuditService::logOrFail(
                'DELETE',
                'RefNotificationChannel',
                $channel->id,
                $oldValues,
                null,
                $request,
            );
        });
    }
}
