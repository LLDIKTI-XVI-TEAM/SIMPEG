<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'super_admin', 'description' => 'Super Admin — akses penuh termasuk konfigurasi sistem dan hard delete'],
            ['name' => 'admin_kepegawaian', 'description' => 'Admin Kepegawaian — CRUD data pegawai, import, riwayat, cuti, EWS, laporan'],
            ['name' => 'pimpinan', 'description' => 'Pimpinan (Kepala Lembaga) — dashboard, read-only data, final approval cuti'],
            ['name' => 'atasan_langsung', 'description' => 'Atasan Langsung — approval stage 1 cuti, read-only data bawahan'],
            ['name' => 'pegawai', 'description' => 'Pegawai — read-only data sendiri, ajukan cuti, lihat notifikasi'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }
    }
}
