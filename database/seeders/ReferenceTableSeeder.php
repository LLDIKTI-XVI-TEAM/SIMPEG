<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Seeder data referensi yang sudah pasti per PRD dan ERD.
// Idempotent: aman dijalankan berulang (keyed pada natural key).
// Tabel yang seed value-nya menunggu LLDIKTI sengaja TIDAK diisi di sini:
// ref_unit_kerja (BLK-03), ref_bup (BLK-13), ref_golongan complete list,
// ref_jenis_cuti policy final, ref_hari_libur tahunan.
class ReferenceTableSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedByNama('ref_agama', [
            'Islam',
            'Kristen',
            'Katolik',
            'Hindu',
            'Buddha',
            'Konghucu',
        ]);

        $this->seedByNama('ref_status_perkawinan', [
            'Belum Kawin',
            'Kawin',
            'Cerai Hidup',
            'Cerai Mati',
        ]);

        // Nilai sesuai data sample daftar_pegawai.xlsx (PNS, CPNS, PPPK).
        $this->seedByNama('ref_jenis_pegawai', [
            'PNS',
            'CPNS',
            'PPPK',
        ]);

        $this->seedByNama('ref_jenjang_pendidikan', [
            'SD',
            'SMP',
            'SMA',
            'D3',
            'D4/S1',
            'S2',
            'S3',
        ]);

        $this->seedEselon();

        $this->seedJenisJabatan();
    }

    /**
     * Seed tabel referensi sederhana yang hanya punya kolom nama + is_active.
     *
     * @param  array<int, string>  $names
     */
    private function seedByNama(string $table, array $names): void
    {
        foreach ($names as $nama) {
            DB::table($table)->updateOrInsert(
                ['nama' => $nama],
                [
                    'id' => (string) Str::uuid(),
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    private function seedEselon(): void
    {
        $eselons = [
            ['kode' => 'I', 'nama' => 'Eselon I'],
            ['kode' => 'II', 'nama' => 'Eselon II'],
            ['kode' => 'III', 'nama' => 'Eselon III'],
            ['kode' => 'IV', 'nama' => 'Eselon IV'],
        ];

        foreach ($eselons as $eselon) {
            DB::table('ref_eselon')->updateOrInsert(
                ['kode' => $eselon['kode']],
                [
                    'id' => (string) Str::uuid(),
                    'nama' => $eselon['nama'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    // Seed awal berdasarkan PRD v1.1: umumnya BUP 58 tahun, jabatan tinggi 60 tahun.
    // Daftar jabatan final dan BUP detail menunggu LLDIKTI (BLK-13); nilai di sini
    // dapat disesuaikan Admin lewat reference table tanpa perubahan kode.
    private function seedJenisJabatan(): void
    {
        $jabatans = [
            ['nama' => 'Fungsional Umum', 'maks_usia_pensiun' => 58],
            ['nama' => 'Fungsional Tertentu', 'maks_usia_pensiun' => 58],
            ['nama' => 'Struktural', 'maks_usia_pensiun' => 58],
            ['nama' => 'Pimpinan Tinggi', 'maks_usia_pensiun' => 60],
        ];

        foreach ($jabatans as $jabatan) {
            DB::table('ref_jenis_jabatan')->updateOrInsert(
                ['nama' => $jabatan['nama']],
                [
                    'id' => (string) Str::uuid(),
                    'maks_usia_pensiun' => $jabatan['maks_usia_pensiun'],
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}
