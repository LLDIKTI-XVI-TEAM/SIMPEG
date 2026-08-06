<?php

namespace Tests\Feature;

use App\Models\NotificationEventChannel;
use App\Models\RefNotificationChannel;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_seeder_includes_jenis_pegawai(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseCount('ref_jenis_pegawai', 3);
        $this->assertDatabaseHas('ref_jenis_pegawai', ['nama' => 'PNS']);
        $this->assertDatabaseHas('ref_jenis_pegawai', ['nama' => 'CPNS']);
        $this->assertDatabaseHas('ref_jenis_pegawai', ['nama' => 'PPPK']);
    }

    public function test_reference_seeder_includes_complete_employee_status_catalogue(): void
    {
        $this->seedReferenceData();

        $statuses = DB::table('ref_status_pegawai')
            ->orderBy('kode')
            ->pluck('nama', 'kode')
            ->all();

        $this->assertSame([
            'AKTIF' => 'Aktif',
            'CLTN' => 'Cuti Luar Tanggungan Negara',
            'HILANG' => 'PNS Dinyatakan Hilang',
            'MUTASI' => 'Mutasi',
            'NONAKTIF' => 'Nonaktif',
            'PEMBERHENTIAN_SEMENTARA' => 'Pemberhentian Sementara',
            'PENSIUN' => 'Pensiun',
            'PERPANJANGAN_CLTN' => 'Perpanjangan CLTN',
            'TUGAS_BELAJAR' => 'Tugas Belajar',
            'WAJIB_MILITER' => 'Wajib Militer',
        ], $statuses);
        $this->assertDatabaseHas('ref_status_pegawai', [
            'kode' => 'TUGAS_BELAJAR',
            'kelompok' => 'Aktif/khusus',
            'is_default' => false,
        ]);
        $this->assertDatabaseHas('ref_status_pegawai', [
            'kode' => 'AKTIF',
            'kelompok' => 'Aktif',
            'is_default' => true,
        ]);
    }

    public function test_reference_seeder_creates_documented_unit_hierarchy(): void
    {
        $this->seedReferenceData();

        $kepalaLembaga = DB::table('ref_unit_kerja')->where('nama', 'Kepala Lembaga')->first();
        $kepalaBagianUmum = DB::table('ref_unit_kerja')->where('nama', 'Kepala Bagian Umum')->first();
        $urusanKeuangan = DB::table('ref_unit_kerja')->where('nama', 'Urusan Keuangan')->first();

        $this->assertNotNull($kepalaLembaga);
        $this->assertNotNull($kepalaBagianUmum);
        $this->assertNotNull($urusanKeuangan);
        $this->assertNull($kepalaLembaga->parent_id);
        $this->assertSame(0, $kepalaLembaga->level);
        $this->assertSame('lembaga', $kepalaLembaga->jenis_unit);
        $this->assertSame($kepalaLembaga->id, $kepalaBagianUmum->parent_id);
        $this->assertSame(1, $kepalaBagianUmum->level);
        $this->assertSame($kepalaBagianUmum->id, $urusanKeuangan->parent_id);
        $this->assertSame(2, $urusanKeuangan->level);
        $this->assertTrue((bool) $urusanKeuangan->is_active);
    }

    public function test_reference_seeder_provisions_configurable_notification_channels_without_resetting_operator_choice(): void
    {
        $this->seedReferenceData();

        $this->assertDatabaseHas('ref_notification_channels', [
            'code' => 'in_app',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('ref_notification_channels', [
            'code' => 'email',
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('ref_notification_channels', [
            'code' => 'whatsapp_business',
            'is_enabled' => false,
        ]);

        DB::table('ref_notification_channels')->where('code', 'email')->update([
            'is_enabled' => false,
            'config' => json_encode(['operator_setting' => 'dipertahankan'], JSON_THROW_ON_ERROR),
        ]);
        app(ReferenceSeeder::class)->run();

        $this->assertDatabaseHas('ref_notification_channels', [
            'code' => 'email',
            'is_enabled' => false,
        ]);
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $this->assertSame(['operator_setting' => 'dipertahankan'], $email->config);
    }

    public function test_reference_seeder_recreates_missing_core_channels_with_documented_defaults(): void
    {
        $this->seedReferenceData();

        $coreChannelIds = RefNotificationChannel::query()
            ->whereIn('code', ['in_app', 'email', 'whatsapp_business'])
            ->pluck('id');
        NotificationEventChannel::query()->whereIn('notification_channel_id', $coreChannelIds)->delete();
        RefNotificationChannel::query()->whereIn('id', $coreChannelIds)->delete();

        app(ReferenceSeeder::class)->run();

        $this->assertDatabaseHas('ref_notification_channels', [
            'code' => 'in_app',
            'is_enabled' => true,
            'config' => null,
        ]);
        $this->assertDatabaseHas('ref_notification_channels', [
            'code' => 'email',
            'is_enabled' => true,
            'config' => null,
        ]);
        $this->assertDatabaseHas('ref_notification_channels', [
            'code' => 'whatsapp_business',
            'is_enabled' => false,
            'config' => null,
        ]);
    }

    public function test_notification_event_channels_enforces_composite_unique_contract(): void
    {
        $this->seedReferenceData();

        $emailChannelId = RefNotificationChannel::query()->where('code', 'email')->value('id');

        $this->expectException(QueryException::class);

        NotificationEventChannel::query()->create([
            'event_key' => 'cuti.pengajuan_baru',
            'notification_channel_id' => $emailChannelId,
            'is_enabled' => true,
        ]);
    }

    public function test_reference_seeder_creates_enabled_policies_for_supported_notification_events(): void
    {
        $this->seedReferenceData();

        $expectedEvents = [
            'cuti.dikembalikan_karena_rollover',
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
            'status_pegawai.diubah',
        ];

        $policies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->whereIn('notification_event_channels.event_key', $expectedEvents)
            ->orderBy('notification_event_channels.event_key')
            ->orderBy('ref_notification_channels.code')
            ->get([
                'notification_event_channels.event_key',
                'notification_event_channels.is_enabled',
                'ref_notification_channels.code',
            ]);

        $this->assertCount(28, $policies);
        $this->assertSame($expectedEvents, $policies->pluck('event_key')->unique()->values()->all());
        $this->assertSame(['email', 'in_app'], $policies->pluck('code')->unique()->sort()->values()->all());
        $this->assertTrue($policies->every(fn (object $policy): bool => (bool) $policy->is_enabled));

        foreach ($expectedEvents as $eventKey) {
            $this->assertSame(['email', 'in_app'], $policies
                ->where('event_key', $eventKey)
                ->pluck('code')
                ->sort()
                ->values()
                ->all());
        }
    }

    public function test_reference_seeder_creates_separate_in_app_only_policy_for_scheduler_failure(): void
    {
        $this->seedReferenceData();

        $policies = DB::table('notification_event_channels')
            ->join('ref_notification_channels', 'ref_notification_channels.id', '=', 'notification_event_channels.notification_channel_id')
            ->where('notification_event_channels.event_key', 'ews.scheduler_failed')
            ->get([
                'notification_event_channels.is_enabled',
                'ref_notification_channels.code',
            ]);

        $this->assertDatabaseCount('notification_event_channels', 35);
        $this->assertCount(1, $policies);
        $this->assertSame('in_app', $policies->sole()->code);
        $this->assertTrue((bool) $policies->sole()->is_enabled);

        DB::table('notification_event_channels')
            ->where('event_key', 'ews.scheduler_failed')
            ->update(['is_enabled' => false]);

        $this->seedReferenceData();

        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'ews.scheduler_failed',
            'is_enabled' => false,
        ]);
    }

    public function test_reference_seeder_preserves_operator_disabled_event_policy_on_rerun(): void
    {
        $this->seedReferenceData();

        $emailChannelId = RefNotificationChannel::query()->where('code', 'email')->value('id');
        DB::table('notification_event_channels')
            ->where('event_key', 'ews.satyalancana')
            ->where('notification_channel_id', $emailChannelId)
            ->update(['is_enabled' => false]);

        $this->seedReferenceData();

        $this->assertDatabaseCount('notification_event_channels', 35);
        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'ews.satyalancana',
            'notification_channel_id' => $emailChannelId,
            'is_enabled' => false,
        ]);
    }

    public function test_notification_event_channel_casts_enabled_state_and_resolves_channel_relation(): void
    {
        $this->seedReferenceData();

        $policy = NotificationEventChannel::query()
            ->where('event_key', 'ews.satyalancana')
            ->whereHas('channel', fn ($query) => $query->where('code', 'email'))
            ->firstOrFail();

        $this->assertIsBool($policy->is_enabled);
        $this->assertTrue($policy->is_enabled);
        $this->assertInstanceOf(RefNotificationChannel::class, $policy->channel);
        $this->assertSame('email', $policy->channel->code);
    }

    public function test_notification_event_channel_foreign_key_restricts_channel_deletion(): void
    {
        $this->seedReferenceData();

        $this->expectException(QueryException::class);

        RefNotificationChannel::query()->where('code', 'email')->delete();
    }

    public function test_reference_seeder_marks_reference_position_active_and_keeps_optional_bup_override_available(): void
    {
        $this->seedReferenceData();

        $jabatan = DB::table('ref_jabatan')->where('nama', 'Analis Kepegawaian')->first();

        $this->assertNotNull($jabatan);
        $this->assertTrue((bool) $jabatan->is_active);
        $this->assertNull($jabatan->default_bup);
    }

    public function test_reference_seeder_is_idempotent(): void
    {
        $this->seed(ReferenceSeeder::class);
        $pnsId = DB::table('ref_jenis_pegawai')->where('nama', 'PNS')->value('id');

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseCount('ref_jenis_pegawai', 3);
        $this->assertSame($pnsId, DB::table('ref_jenis_pegawai')->where('nama', 'PNS')->value('id'));
    }

    public function test_reference_seeder_includes_core_2026_hari_libur(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('ref_hari_libur', [
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);
        $this->assertDatabaseHas('ref_hari_libur', [
            'tanggal' => '2026-08-17',
            'nama' => 'Hari Kemerdekaan Republik Indonesia',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);
        $this->assertDatabaseHas('ref_hari_libur', [
            'tanggal' => '2026-12-25',
            'nama' => 'Hari Raya Natal',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);
    }

    public function test_reference_seeder_keeps_hari_libur_idempotent(): void
    {
        $this->seed(ReferenceSeeder::class);
        $newYearId = DB::table('ref_hari_libur')->where('tanggal', '2026-01-01')->value('id');

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseCount('ref_hari_libur', 6);
        $this->assertSame($newYearId, DB::table('ref_hari_libur')->where('tanggal', '2026-01-01')->value('id'));
        $this->assertDatabaseHas('ref_hari_libur', [
            'tanggal' => '2026-01-01',
            'nama' => 'Tahun Baru Masehi',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);
        $this->assertDatabaseHas('ref_hari_libur', [
            'tanggal' => '2026-12-25',
            'nama' => 'Hari Raya Natal',
            'tahun' => 2026,
            'is_cuti_bersama' => false,
        ]);
    }

    public function test_reference_seeder_includes_stable_leave_type_metadata(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('ref_jenis_cuti', [
            'nama' => 'Cuti Tahunan',
            'code' => 'tahunan',
            'mengurangi_saldo_tahunan' => true,
            'khusus_pns' => false,
        ]);
        $this->assertDatabaseHas('ref_jenis_cuti', [
            'nama' => 'Cuti Besar',
            'code' => 'besar',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);
        $this->assertDatabaseHas('ref_jenis_cuti', [
            'nama' => 'Cuti Luar Tanggungan Negara (CLTN)',
            'code' => 'cltn',
            'mengurangi_saldo_tahunan' => false,
            'khusus_pns' => true,
        ]);
    }
}
