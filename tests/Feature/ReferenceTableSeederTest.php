<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReferenceTableSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_tables_terisi_data_aman(): void
    {
        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);

        $this->assertDatabaseCount('ref_agama', 6);
        $this->assertDatabaseCount('ref_status_perkawinan', 4);
        $this->assertDatabaseCount('ref_jenis_pegawai', 3);
        $this->assertDatabaseCount('ref_jenjang_pendidikan', 7);
        $this->assertDatabaseCount('ref_eselon', 4);
        $this->assertDatabaseCount('ref_jenis_jabatan', 4);

        $this->assertDatabaseHas('ref_jenis_pegawai', ['nama' => 'PPPK']);
        $this->assertDatabaseHas('ref_jenis_jabatan', [
            'nama' => 'Pimpinan Tinggi',
            'maks_usia_pensiun' => 60,
        ]);
    }

    public function test_seeder_idempotent_saat_dijalankan_berulang(): void
    {
        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);
        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);

        // Tidak ada duplikasi setelah dijalankan dua kali.
        $this->assertDatabaseCount('ref_agama', 6);
        $this->assertDatabaseCount('ref_jenis_pegawai', 3);
    }

    public function test_tabel_referensi_yang_menunggu_lldikti_kosong(): void
    {
        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);

        // ref_unit_kerja (BLK-03) dan ref_bup (BLK-13) sengaja tidak di-seed.
        $this->assertSame(0, DB::table('ref_unit_kerja')->count());
        $this->assertSame(0, DB::table('ref_bup')->count());
    }
}
