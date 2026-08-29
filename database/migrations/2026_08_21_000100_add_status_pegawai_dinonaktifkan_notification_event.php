<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $channels = DB::table('ref_notification_channels')
            ->whereIn('code', ['in_app', 'email'])
            ->pluck('id', 'code');

        foreach (['in_app', 'email'] as $channelCode) {
            $channelId = $channels->get($channelCode);
            if ($channelId === null) {
                continue;
            }

            $exists = DB::table('notification_event_channels')
                ->where('event_key', 'status_pegawai.dinonaktifkan')
                ->where('notification_channel_id', $channelId)
                ->exists();

            if (! $exists) {
                DB::table('notification_event_channels')->insert([
                    'id' => (string) Str::uuid(),
                    'event_key' => 'status_pegawai.dinonaktifkan',
                    'notification_channel_id' => $channelId,
                    'is_enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_channels') || ! Schema::hasTable('ref_notification_channels')) {
            return;
        }

        DB::table('notification_event_channels')
            ->where('event_key', 'status_pegawai.dinonaktifkan')
            ->delete();
    }
};
