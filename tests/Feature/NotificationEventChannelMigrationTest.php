<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
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

        $dutyPostponementPolicies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->where('notification_event_channels.event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->get(['notification_event_channels.is_enabled', 'ref_notification_channels.code']);

        $this->assertSame(['email', 'in_app'], $dutyPostponementPolicies->pluck('code')->sort()->values()->all());
        $this->assertTrue($dutyPostponementPolicies->every(fn (object $policy): bool => (bool) $policy->is_enabled));
        $rolloverReturnPolicies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->where('notification_event_channels.event_key', 'cuti.dikembalikan_karena_rollover')
            ->get(['notification_event_channels.is_enabled', 'ref_notification_channels.code']);

        $this->assertSame(['email', 'in_app'], $rolloverReturnPolicies->pluck('code')->sort()->values()->all());
        $this->assertTrue($rolloverReturnPolicies->every(fn (object $policy): bool => (bool) $policy->is_enabled));
        $this->assertDatabaseCount('notification_event_channels', 31);

        $orphanCount = DB::table('notification_event_channels')
            ->leftJoin('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->whereNull('ref_notification_channels.id')
            ->count();

        $this->assertSame(0, $orphanCount);
        $this->assertDatabaseHas('ref_notification_channels', ['code' => 'in_app', 'is_enabled' => true]);
        $this->assertDatabaseHas('ref_notification_channels', ['code' => 'email', 'is_enabled' => true]);
        $this->assertDatabaseHas('ref_notification_channels', ['code' => 'whatsapp_business', 'is_enabled' => false]);
    }

    public function test_rollover_return_migration_removes_only_its_own_notification_policies_on_rollback(): void
    {
        $migration = $this->rolloverReturnMigration();
        $rolloverPolicyCount = DB::table('notification_event_channels')
            ->where('event_key', 'cuti.dikembalikan_karena_rollover')
            ->count();
        $dutyPostponementPolicyCount = DB::table('notification_event_channels')
            ->where('event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->count();

        $this->assertSame(2, $rolloverPolicyCount);
        $this->assertSame(2, $dutyPostponementPolicyCount);

        $this->invokeMigrationMethod($migration, 'down');

        $this->assertDatabaseMissing('notification_event_channels', [
            'event_key' => 'cuti.dikembalikan_karena_rollover',
        ]);
        $this->assertSame(2, DB::table('notification_event_channels')
            ->where('event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->count());

        $this->invokeMigrationMethod($migration, 'up');

        $this->assertSame(2, DB::table('notification_event_channels')
            ->where('event_key', 'cuti.dikembalikan_karena_rollover')
            ->count());
    }

    private function rolloverReturnMigration(): Migration
    {
        return require database_path('migrations/2026_08_04_000001_add_rollover_return_support_to_leave_requests.php');
    }

    private function invokeMigrationMethod(Migration $migration, string $method): void
    {
        $callback = [$migration, $method];
        $this->assertIsCallable($callback);
        call_user_func($callback);
    }
}
