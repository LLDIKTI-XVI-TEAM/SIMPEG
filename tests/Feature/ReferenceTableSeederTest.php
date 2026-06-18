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
        $this->assertDatabaseCount('ref_status_perkawinan', 3);
        $this->assertDatabaseCount('ref_jenis_pegawai', 3);
        $this->assertDatabaseCount('ref_jenis_kelamin', 2);
        $this->assertDatabaseCount('ref_jenjang_pendidikan', 9);
        $this->assertDatabaseCount('ref_eselon', 8);
        $this->assertDatabaseCount('ref_jenis_jabatan', 3);
        $this->assertDatabaseCount('ref_golongan', 17);

        $this->assertDatabaseHas('ref_agama', ['nama' => 'Kristen Protestan']);
        $this->assertDatabaseMissing('ref_agama', ['nama' => 'Kristen']);
        $this->assertDatabaseHas('ref_status_perkawinan', ['nama' => 'Duda / Janda']);
        $this->assertDatabaseHas('ref_jenis_pegawai', ['nama' => 'PPPK']);
        $this->assertDatabaseHas('ref_jenis_kelamin', ['kode' => 'L', 'nama' => 'Laki-laki']);
        $this->assertDatabaseHas('ref_jenis_kelamin', ['kode' => 'P', 'nama' => 'Perempuan']);
        $this->assertDatabaseHas('ref_jenjang_pendidikan', ['nama' => 'SMA / SMK / Sederajat']);
        $this->assertDatabaseHas('ref_jenjang_pendidikan', ['nama' => 'D4 / S1']);
        $this->assertDatabaseHas('ref_jenjang_pendidikan', ['nama' => 'S2 / Profesi']);
        $this->assertDatabaseHas('ref_eselon', ['kode' => 'IV.b', 'nama' => 'Eselon IV.b']);
        $this->assertDatabaseHas('ref_jenis_jabatan', [
            'nama' => 'Struktural',
            'maks_usia_pensiun' => 60,
        ]);
        $this->assertDatabaseHas('ref_jenis_jabatan', [
            'nama' => 'Fungsional Tertentu',
            'maks_usia_pensiun' => 58,
        ]);
        $this->assertDatabaseHas('ref_jenis_jabatan', [
            'nama' => 'Fungsional Umum / Pelaksana',
            'maks_usia_pensiun' => 58,
        ]);
        $this->assertDatabaseMissing('ref_jenis_jabatan', ['nama' => 'Pimpinan Tinggi']);
        $this->assertDatabaseHas('ref_golongan', ['kode' => 'I/a', 'nama' => 'Juru Muda']);
        $this->assertDatabaseHas('ref_golongan', ['kode' => 'IV/e', 'nama' => 'Pembina Utama']);
    }

    public function test_seeder_idempotent_saat_dijalankan_berulang(): void
    {
        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);
        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);

        // Tidak ada duplikasi setelah dijalankan dua kali.
        $this->assertDatabaseCount('ref_agama', 6);
        $this->assertDatabaseCount('ref_jenis_pegawai', 3);
        $this->assertDatabaseCount('ref_jenis_kelamin', 2);
        $this->assertDatabaseCount('ref_golongan', 17);
    }

    public function test_seeder_tidak_mengubah_uuid_saat_dijalankan_berulang(): void
    {
        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);

        $agamaId = DB::table('ref_agama')->where('nama', 'Islam')->value('id');
        $golonganId = DB::table('ref_golongan')->where('kode', 'III/d')->value('id');

        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);

        $this->assertSame($agamaId, DB::table('ref_agama')->where('nama', 'Islam')->value('id'));
        $this->assertSame($golonganId, DB::table('ref_golongan')->where('kode', 'III/d')->value('id'));
    }

    public function test_tabel_referensi_yang_menunggu_lldikti_kosong(): void
    {
        $this->seed(\Database\Seeders\ReferenceTableSeeder::class);

        // ref_unit_kerja (BLK-03) dan ref_bup (BLK-13) sengaja tidak di-seed.
        $this->assertSame(0, DB::table('ref_unit_kerja')->count());
        $this->assertSame(0, DB::table('ref_bup')->count());
    }
}
