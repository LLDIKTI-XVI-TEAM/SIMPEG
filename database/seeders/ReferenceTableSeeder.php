<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Seeder data referensi yang sudah pasti per PRD dan ERD.
// Idempotent: aman dijalankan berulang (keyed pada natural key).
// Tabel yang seed value-nya menunggu LLDIKTI sengaja TIDAK diisi di sini:
// ref_unit_kerja (BLK-03), ref_bup (BLK-13), ref_hari_libur tahunan.
class ReferenceTableSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedByNama('ref_agama', [
            'Islam',
            'Kristen Protestan',
            'Katolik',
            'Hindu',
            'Buddha',
            'Konghucu',
        ]);

        $this->seedByNama('ref_status_perkawinan', [
            'Belum Menikah',
            'Menikah',
            'Duda / Janda',
        ]);

        // Nilai sesuai data sample daftar_pegawai.xlsx (PNS, CPNS, PPPK).
        $this->seedByNama('ref_jenis_pegawai', [
            'PNS',
            'CPNS',
            'PPPK',
        ]);

        $this->seedJenisKelamin();

        $this->seedByNama('ref_jenjang_pendidikan', [
            'SD',
            'SMP',
            'SMA / SMK / Sederajat',
            'D1',
            'D2',
            'D3',
            'D4 / S1',
            'S2 / Profesi',
            'S3',
        ]);

        $this->seedGolongan();
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
            $this->upsertReference($table, ['nama' => $nama], ['is_active' => true]);
        }
    }

    /**
     * Update data referensi tanpa mengganti UUID ketika seeder dijalankan berulang.
     *
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $values
     */
    private function upsertReference(string $table, array $key, array $values): void
    {
        $now = now();

        if (DB::table($table)->where($key)->exists()) {
            DB::table($table)->where($key)->update($values + ['updated_at' => $now]);

            return;
        }

        DB::table($table)->insert($key + $values + [
            'id' => (string) Str::uuid(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function seedJenisKelamin(): void
    {
        $jenisKelamin = [
            ['kode' => 'L', 'nama' => 'Laki-laki'],
            ['kode' => 'P', 'nama' => 'Perempuan'],
        ];

        foreach ($jenisKelamin as $item) {
            $this->upsertReference('ref_jenis_kelamin', ['kode' => $item['kode']], [
                'nama' => $item['nama'],
                'is_active' => true,
            ]);
        }
    }

    private function seedGolongan(): void
    {
        $golongans = [
            ['kode' => 'I/a', 'nama' => 'Juru Muda'],
            ['kode' => 'I/b', 'nama' => 'Juru Muda Tingkat I'],
            ['kode' => 'I/c', 'nama' => 'Juru'],
            ['kode' => 'I/d', 'nama' => 'Juru Tingkat I'],
            ['kode' => 'II/a', 'nama' => 'Pengatur Muda'],
            ['kode' => 'II/b', 'nama' => 'Pengatur Muda Tingkat I'],
            ['kode' => 'II/c', 'nama' => 'Pengatur'],
            ['kode' => 'II/d', 'nama' => 'Pengatur Tingkat I'],
            ['kode' => 'III/a', 'nama' => 'Penata Muda'],
            ['kode' => 'III/b', 'nama' => 'Penata Muda Tingkat I'],
            ['kode' => 'III/c', 'nama' => 'Penata'],
            ['kode' => 'III/d', 'nama' => 'Penata Tingkat I'],
            ['kode' => 'IV/a', 'nama' => 'Pembina'],
            ['kode' => 'IV/b', 'nama' => 'Pembina Tingkat I'],
            ['kode' => 'IV/c', 'nama' => 'Pembina Utama Muda'],
            ['kode' => 'IV/d', 'nama' => 'Pembina Utama Madya'],
            ['kode' => 'IV/e', 'nama' => 'Pembina Utama'],
        ];

        foreach ($golongans as $golongan) {
            $this->upsertReference('ref_golongan', ['kode' => $golongan['kode']], [
                'nama' => $golongan['nama'],
                'is_active' => true,
            ]);
        }
    }

    private function seedEselon(): void
    {
        $eselons = [
            ['kode' => 'I.a', 'nama' => 'Eselon I.a'],
            ['kode' => 'I.b', 'nama' => 'Eselon I.b'],
            ['kode' => 'II.a', 'nama' => 'Eselon II.a'],
            ['kode' => 'II.b', 'nama' => 'Eselon II.b'],
            ['kode' => 'III.a', 'nama' => 'Eselon III.a'],
            ['kode' => 'III.b', 'nama' => 'Eselon III.b'],
            ['kode' => 'IV.a', 'nama' => 'Eselon IV.a'],
            ['kode' => 'IV.b', 'nama' => 'Eselon IV.b'],
        ];

        foreach ($eselons as $eselon) {
            $this->upsertReference('ref_eselon', ['kode' => $eselon['kode']], [
                'nama' => $eselon['nama'],
                'is_active' => true,
            ]);
        }
    }

    // Seed awal berdasarkan PRD v1.1. Detail jabatan dan BUP spesifik tetap menunggu LLDIKTI (BLK-13).
    private function seedJenisJabatan(): void
    {
        $jabatans = [
            ['nama' => 'Struktural', 'maks_usia_pensiun' => 60],
            ['nama' => 'Fungsional Tertentu', 'maks_usia_pensiun' => 58],
            ['nama' => 'Fungsional Umum / Pelaksana', 'maks_usia_pensiun' => 58],
        ];

        foreach ($jabatans as $jabatan) {
            $this->upsertReference('ref_jenis_jabatan', ['nama' => $jabatan['nama']], [
                'maks_usia_pensiun' => $jabatan['maks_usia_pensiun'],
                'is_active' => true,
            ]);
        }
    }
}
