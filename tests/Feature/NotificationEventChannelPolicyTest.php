<?php

namespace Tests\Feature;

use App\Models\RefNotificationChannel;
use App\Services\Notifications\NotificationEventCatalog;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationEventChannelPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duty_postponement_event_catalog_and_enabled_channel_policies_are_available(): void
    {
        $catalog = app(NotificationEventCatalog::class);
        $event = $catalog->events()['cuti.ditangguhkan_tugas_dinas'] ?? null;

        $this->assertSame([
            'label' => 'Cuti ditangguhkan karena tugas dinas',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ], $event);
        $this->assertTrue($catalog->supportsChannel('cuti.ditangguhkan_tugas_dinas', 'in_app'));
        $this->assertTrue($catalog->supportsChannel('cuti.ditangguhkan_tugas_dinas', 'email'));

        $enabledChannels = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->where('notification_event_channels.event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->where('notification_event_channels.is_enabled', true)
            ->orderBy('ref_notification_channels.code')
            ->pluck('ref_notification_channels.code')
            ->all();

        $this->assertSame(['email', 'in_app'], $enabledChannels);
    }

    public function test_rollover_return_event_catalog_and_seeder_policy_preserve_operator_choice(): void
    {
        $catalog = app(NotificationEventCatalog::class);
        $event = $catalog->events()['cuti.dikembalikan_karena_rollover'] ?? null;

        $this->assertSame([
            'label' => 'Cuti dikembalikan karena rollover',
            'group' => 'Cuti',
            'allowed_channels' => ['in_app', 'email', 'whatsapp_business'],
        ], $event);

        $emailChannelId = RefNotificationChannel::query()->where('code', 'email')->value('id');
        $inAppChannelId = RefNotificationChannel::query()->where('code', 'in_app')->value('id');

        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'cuti.dikembalikan_karena_rollover',
            'notification_channel_id' => $emailChannelId,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'cuti.dikembalikan_karena_rollover',
            'notification_channel_id' => $inAppChannelId,
            'is_enabled' => true,
        ]);

        DB::table('notification_event_channels')
            ->where('event_key', 'cuti.dikembalikan_karena_rollover')
            ->where('notification_channel_id', $emailChannelId)
            ->update(['is_enabled' => false]);
        DB::table('notification_event_channels')
            ->where('event_key', 'cuti.dikembalikan_karena_rollover')
            ->where('notification_channel_id', $inAppChannelId)
            ->delete();

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'cuti.dikembalikan_karena_rollover',
            'notification_channel_id' => $emailChannelId,
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'cuti.dikembalikan_karena_rollover',
            'notification_channel_id' => $inAppChannelId,
            'is_enabled' => true,
        ]);
    }

    public function test_duty_postponement_reference_seeder_repairs_missing_policy_without_overwriting_operator_choice(): void
    {
        $emailChannelId = RefNotificationChannel::query()->where('code', 'email')->value('id');
        $inAppChannelId = RefNotificationChannel::query()->where('code', 'in_app')->value('id');

        DB::table('notification_event_channels')
            ->where('event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->where('notification_channel_id', $emailChannelId)
            ->update(['is_enabled' => false]);
        DB::table('notification_event_channels')
            ->where('event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->where('notification_channel_id', $inAppChannelId)
            ->delete();

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'cuti.ditangguhkan_tugas_dinas',
            'notification_channel_id' => $emailChannelId,
            'is_enabled' => false,
        ]);
        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'cuti.ditangguhkan_tugas_dinas',
            'notification_channel_id' => $inAppChannelId,
            'is_enabled' => true,
        ]);
    }
}
