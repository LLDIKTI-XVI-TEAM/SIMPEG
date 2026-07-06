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
