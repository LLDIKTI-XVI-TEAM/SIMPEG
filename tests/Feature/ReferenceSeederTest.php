<?php

namespace Tests\Feature;

use Database\Seeders\ReferenceSeeder;
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

        DB::table('ref_notification_channels')->where('code', 'email')->update(['is_enabled' => false]);
        $this->seedReferenceData();

        $this->assertDatabaseHas('ref_notification_channels', [
            'code' => 'email',
            'is_enabled' => false,
        ]);
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
