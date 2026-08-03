<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NotificationEventChannelMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_backfill_complete_notification_policy_defaults_without_seeder(): void
    {
        $normalEvents = [
            'cuti.disetujui',
            'cuti.ditunda',
            'cuti.menunggu_persetujuan',
            'cuti.pengajuan_baru',
            'cuti.perlu_perubahan',
            'cuti.tidak_disetujui',
            'ews.kenaikan_pangkat',
            'ews.kgb',
            'ews.kontrak_pppk',
            'ews.pensiun',
            'ews.satyalancana',
            'ews.tidak_perlu',
        ];

        $normalPolicies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->whereIn('notification_event_channels.event_key', $normalEvents)
            ->get([
                'notification_event_channels.event_key',
                'notification_event_channels.is_enabled',
                'ref_notification_channels.code',
            ]);

        $this->assertCount(24, $normalPolicies);
        $this->assertTrue($normalPolicies->every(fn (object $policy): bool => (bool) $policy->is_enabled));

        foreach ($normalEvents as $eventKey) {
            $this->assertSame(['email', 'in_app'], $normalPolicies
                ->where('event_key', $eventKey)
                ->pluck('code')
                ->sort()
                ->values()
                ->all());
        }

        $schedulerPolicies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->where('notification_event_channels.event_key', 'ews.scheduler_failed')
            ->get(['notification_event_channels.is_enabled', 'ref_notification_channels.code']);

        $this->assertCount(1, $schedulerPolicies);
        $this->assertSame('in_app', $schedulerPolicies->sole()->code);
        $this->assertTrue((bool) $schedulerPolicies->sole()->is_enabled);

        $statusPegawaiPolicies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->where('notification_event_channels.event_key', 'status_pegawai.diubah')
            ->get(['notification_event_channels.is_enabled', 'ref_notification_channels.code']);

        $this->assertCount(2, $statusPegawaiPolicies);
        $this->assertTrue($statusPegawaiPolicies->every(fn (object $policy): bool => (bool) $policy->is_enabled));

        $this->assertDatabaseCount('notification_event_channels', 27);

        $orphanCount = DB::table('notification_event_channels')
            ->leftJoin('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->whereNull('ref_notification_channels.id')
            ->count();

        $this->assertSame(0, $orphanCount);
        $this->assertDatabaseHas('ref_notification_channels', ['code' => 'in_app', 'is_enabled' => true]);
        $this->assertDatabaseHas('ref_notification_channels', ['code' => 'email', 'is_enabled' => true]);
        $this->assertDatabaseHas('ref_notification_channels', ['code' => 'whatsapp_business', 'is_enabled' => false]);
    }
}
