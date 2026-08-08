<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $eventKeys = [
            'ews.followup.kenaikan_pangkat',
            'ews.followup.kgb',
            'ews.followup.pensiun',
            'ews.followup.kontrak_pppk',
            'ews.followup.satyalancana',
            'ews.followup.tidak_perlu',
        ];

        // Email untuk hasil tindak lanjut sengaja belum didaftarkan; cukup in_app
        // sampai keputusan produk membuka channel email lewat konfigurasi.
        foreach (['in_app'] as $channelCode) {
            $channelId = DB::table('ref_notification_channels')->where('code', $channelCode)->value('id');
            if ($channelId === null) {
                continue;
            }

            foreach ($eventKeys as $eventKey) {
                $exists = DB::table('notification_event_channels')
                    ->where('event_key', $eventKey)
                    ->where('notification_channel_id', $channelId)
                    ->exists();

                if (! $exists) {
                    DB::table('notification_event_channels')->insert([
                        'id' => (string) Str::uuid(),
                        'event_key' => $eventKey,
                        'notification_channel_id' => $channelId,
                        'is_enabled' => true,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notification_event_channels')
            ->where('event_key', 'like', 'ews.followup.%')
            ->delete();
    }
};
