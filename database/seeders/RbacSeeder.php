<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        // Daftar role aplikasi sesuai permission matrix SIMPEG.
        $roles = [
            'super_admin' => 'Super Admin — akses penuh termasuk konfigurasi sistem dan soft delete/restore',
            'admin_kepegawaian' => 'Admin Kepegawaian — CRUD data pegawai, import, riwayat, cuti, EWS, laporan',
            'pimpinan' => 'Pimpinan (Kepala Lembaga) — dashboard, read-only data, final approval cuti',
            'atasan_langsung' => 'Atasan Langsung — approval stage 1 cuti, read-only data bawahan',
            'pegawai' => 'Pegawai — read-only data sendiri, ajukan cuti, lihat notifikasi',
        ];

        // Permission level aksi memakai konvensi module.action agar mudah diaudit dan diperluas.
        $permissions = [
            'employees.read' => ['module' => 'employees', 'description' => 'Melihat daftar data pegawai'],
            'employees.create' => ['module' => 'employees', 'description' => 'Membuat data pegawai'],
            'employees.update' => ['module' => 'employees', 'description' => 'Mengubah data pegawai'],
            'employees.import' => ['module' => 'employees', 'description' => 'Import data pegawai'],
            'employee_histories.read' => ['module' => 'employee_histories', 'description' => 'Melihat riwayat pegawai'],
            'employee_histories.create' => ['module' => 'employee_histories', 'description' => 'Membuat entri riwayat pegawai'],
            'hari_libur.read' => ['module' => 'hari_libur', 'description' => 'Melihat hari libur dan cuti bersama'],
            'hari_libur.create' => ['module' => 'hari_libur', 'description' => 'Membuat hari libur dan cuti bersama'],
            'hari_libur.update' => ['module' => 'hari_libur', 'description' => 'Mengubah hari libur dan cuti bersama'],
            'hari_libur.delete' => ['module' => 'hari_libur', 'description' => 'Menghapus hari libur dan cuti bersama'],
            'audit_logs.read' => ['module' => 'audit_logs', 'description' => 'Melihat audit log sistem'],
            'notifications.read' => ['module' => 'notifications', 'description' => 'Melihat notifikasi milik sendiri'],
            'notifications.update' => ['module' => 'notifications', 'description' => 'Menandai notifikasi milik sendiri sudah dibaca'],
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
        // hari_libur tetap khusus super_admin; pimpinan/atasan/pegawai belum punya akses route admin.
        $this->syncRolePermissions([
            'super_admin' => array_keys($permissions),
            'admin_kepegawaian' => [
                'employees.read',
                'employees.create',
                'employees.update',
                'employees.import',
                'employee_histories.read',
                'employee_histories.create',
                'audit_logs.read',
                'notifications.read',
                'notifications.update',
            ],
            'pimpinan' => ['notifications.read', 'notifications.update'],
            'atasan_langsung' => ['notifications.read', 'notifications.update'],
            'pegawai' => ['notifications.read', 'notifications.update'],
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
