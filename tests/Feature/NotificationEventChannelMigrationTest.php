<?php

namespace Tests\Feature;

use App\Jobs\SendSimpegNotificationEmailJob;
use App\Models\Employee;
use App\Models\RefStatusPegawai;
use App\Models\SimpegNotification;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotificationEventChannelMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_memakai_kebijakan_generik_hasil_migration_untuk_in_app_dan_email(): void
    {
        Queue::fake();
        $this->seed(RbacSeeder::class);

        $this->assertDatabaseMissing('notification_event_channels', [
            'event_key' => 'status_pegawai.diaktifkan_kembali',
        ]);

        $user = User::factory()->superAdmin()->create();
        $nonaktif = RefStatusPegawai::query()->where('kode', 'NONAKTIF')->firstOrFail();
        $employee = Employee::factory()->create(['email_pribadi' => 'pegawai@example.test']);
        $employee->status_pegawai_id = $nonaktif->id;
        $employee->save();

        $this->actingAs($user)
            ->withSession(['_token' => 'test-token'])
            ->postJson("/api/v1/pegawai/{$employee->id}/restore", [
                '_token' => 'test-token',
                'tanggal_efektif' => now()->toDateString(),
                'alasan' => 'Masa sanksi berakhir.',
            ])
            ->assertOk();

        $notification = SimpegNotification::query()
            ->where('user_id', $employee->id)
            ->sole();

        $this->assertSame('status_pegawai.diubah', $notification->type);
        $this->assertSame('Akun Anda Telah Diaktifkan Kembali', $notification->title);
        $this->assertSame(
            'Status kepegawaian Anda telah diaktifkan kembali. Keterangan: Masa sanksi berakhir.',
            $notification->body,
        );
        $this->assertSame(route('profil', [], false), $notification->data['url']);

        Queue::assertPushed(
            SendSimpegNotificationEmailJob::class,
            fn (SendSimpegNotificationEmailJob $job): bool => $job->employeeId === $employee->id
                && $job->eventKey === 'status_pegawai.diubah'
                && $job->title === 'Akun Anda Telah Diaktifkan Kembali'
                && $job->body === 'Status kepegawaian Anda telah diaktifkan kembali. Keterangan: Masa sanksi berakhir.',
        );
    }

    public function test_migrations_backfill_complete_notification_policy_defaults_without_seeder(): void
    {
        $normalEvents = [
            'cuti.disetujui',
            'cuti.ditunda',
            'cuti.menunggu_persetujuan',
            'cuti.pembatalan_diajukan',
            'cuti.pembatalan_disetujui',
            'cuti.pembatalan_ditolak',
            'cuti.pengajuan_baru',
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

        $this->assertCount(28, $normalPolicies);
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
            ->whereIn('notification_event_channels.event_key', ['status_pegawai.diubah', 'status_pegawai.dinonaktifkan'])
            ->get(['notification_event_channels.is_enabled', 'ref_notification_channels.code']);

        $this->assertCount(4, $statusPegawaiPolicies);
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

        // Event impor hanya memakai in_app; kedua barisnya sudah ada sebelum migration
        // 2026_08_05 sehingga migration tersebut tidak menambah baris baru.
        $importPolicies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->whereIn('notification_event_channels.event_key', ['import_pegawai', 'import_pegawai_gagal'])
            ->get([
                'notification_event_channels.event_key',
                'notification_event_channels.is_enabled',
                'ref_notification_channels.code',
            ]);

        $this->assertCount(2, $importPolicies);
        $this->assertTrue($importPolicies->every(fn (object $policy): bool => (bool) $policy->is_enabled));
        $this->assertTrue($importPolicies->every(fn (object $policy): bool => $policy->code === 'in_app'));

        // Event hasil tindak lanjut EWS (migration 2026_08_07) untuk sementara hanya
        // in_app; email dinyalakan lewat konfigurasi channel saat dibutuhkan.
        $followupEvents = [
            'ews.followup.kenaikan_pangkat',
            'ews.followup.kgb',
            'ews.followup.pensiun',
            'ews.followup.kontrak_pppk',
            'ews.followup.satyalancana',
            'ews.followup.tidak_perlu',
        ];

        $followupPolicies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->whereIn('notification_event_channels.event_key', $followupEvents)
            ->get([
                'notification_event_channels.event_key',
                'notification_event_channels.is_enabled',
                'ref_notification_channels.code',
            ]);

        $this->assertCount(6, $followupPolicies);
        $this->assertTrue($followupPolicies->every(fn (object $policy): bool => (bool) $policy->is_enabled));
        $this->assertTrue($followupPolicies->every(fn (object $policy): bool => $policy->code === 'in_app'));

        // Agregat 45 = 37 kebijakan existing + 6 baris dari 6 event ews.followup.* (in_app saja)
        // + 2 baris dari status_pegawai.dinonaktifkan (in_app + email).
        $this->assertDatabaseCount('notification_event_channels', 45);

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

    public function test_cancellation_rollback_restores_previous_notification_policy_defaults(): void
    {
        $migration = require database_path('migrations/2026_09_03_000002_add_leave_cancellation_access_and_notification_policies.php');
        $this->invokeMigrationMethod($migration, 'down');

        $policies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->where('event_key', 'cuti.perlu_perubahan')
            ->get(['notification_event_channels.is_enabled', 'ref_notification_channels.code']);

        $this->assertSame(['email', 'in_app'], $policies->pluck('code')->sort()->values()->all());
        $this->assertTrue($policies->every(fn (object $policy): bool => (bool) $policy->is_enabled));

        DB::table('notification_event_channels')->where('event_key', 'cuti.perlu_perubahan')->update(['is_enabled' => false]);
        $this->invokeMigrationMethod($migration, 'down');
        $this->assertSame(2, DB::table('notification_event_channels')
            ->where('event_key', 'cuti.perlu_perubahan')->where('is_enabled', false)->count());

        $this->invokeMigrationMethod($migration, 'up');
        $this->assertDatabaseMissing('notification_event_channels', ['event_key' => 'cuti.perlu_perubahan']);
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
