<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // Daftar role Fase 1 sesuai permission matrix SIMPEG (5 role).
        $roles = [
            'super_admin' => 'Super Admin — akses penuh termasuk konfigurasi sistem dan hard delete',
            'admin_kepegawaian' => 'Admin Kepegawaian — CRUD data pegawai, import, riwayat, cuti, EWS, laporan',
            'pimpinan' => 'Pimpinan (Kepala Lembaga) — dashboard, read-only data, final approval cuti',
            'atasan_langsung' => 'Atasan Langsung — approval stage 1 cuti, read-only data bawahan',
            'pegawai' => 'Pegawai — read-only data sendiri, ajukan cuti, lihat notifikasi',
        ];

        // Permission level aksi memakai konvensi module.action agar mudah diaudit dan diperluas.
        $permissions = [
            'employees.create' => ['module' => 'employees', 'description' => 'Membuat data pegawai'],
            'employees.import' => ['module' => 'employees', 'description' => 'Import data pegawai'],
            'hari_libur.read' => ['module' => 'hari_libur', 'description' => 'Melihat hari libur dan cuti bersama'],
            'hari_libur.create' => ['module' => 'hari_libur', 'description' => 'Membuat hari libur dan cuti bersama'],
            'hari_libur.update' => ['module' => 'hari_libur', 'description' => 'Mengubah hari libur dan cuti bersama'],
            'hari_libur.delete' => ['module' => 'hari_libur', 'description' => 'Menghapus hari libur dan cuti bersama'],
            'audit_logs.read' => ['module' => 'audit_logs', 'description' => 'Melihat audit log sistem'],
        ];

        foreach ($roles as $name => $description) {
            Role::firstOrCreate(['name' => $name], [
                'guard_name' => 'web',
                'description' => $description,
            ]);
        }

        foreach ($permissions as $name => $attributes) {
            Permission::firstOrCreate(['name' => $name], $attributes);
        }

        // Mapping permission per role dibuat eksplisit agar perubahan hak akses mudah ditelusuri saat review.
        // hari_libur tetap khusus super_admin pada Fase 1; pimpinan/atasan/pegawai belum punya akses route admin.
        $this->syncRolePermissions([
            'super_admin' => array_keys($permissions),
            'admin_kepegawaian' => [
                'employees.create',
                'employees.import',
                'audit_logs.read',
            ],
            'pimpinan' => [],
            'atasan_langsung' => [],
            'pegawai' => [],
        ]);
    }

    /**
     * Menyinkronkan mapping role-permission secara idempoten.
     * Memakai sync agar seeder aman dijalankan ulang tanpa menduplikasi pivot.
     *
     * @param  array<string, list<string>>  $mapping
     */
    private function syncRolePermissions(array $mapping): void
    {
        foreach ($mapping as $roleName => $permissionNames) {
            $role = Role::where('name', $roleName)->firstOrFail();

            $permissionIds = Permission::query()
                ->whereIn('name', $permissionNames)
                ->pluck('id')
                ->all();

            $role->permissions()->sync($permissionIds);
        }
    }
}
