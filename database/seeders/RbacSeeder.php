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
            'dashboard.read' => ['module' => 'dashboard', 'description' => 'Melihat dashboard sesuai role'],
            'employees.read' => ['module' => 'employees', 'description' => 'Melihat daftar data pegawai'],
            'employees.create' => ['module' => 'employees', 'description' => 'Membuat data pegawai'],
            'employees.update' => ['module' => 'employees', 'description' => 'Mengubah data pegawai'],
            'employees.deactivate' => ['module' => 'employees', 'description' => 'Menonaktifkan data pegawai'],
            'employees.restore' => ['module' => 'employees', 'description' => 'Memulihkan data pegawai nonaktif'],
            'employees.import' => ['module' => 'employees', 'description' => 'Import data pegawai'],
            'employees.export' => ['module' => 'employees', 'description' => 'Export data pegawai'],
            'employee_histories.read' => ['module' => 'employee_histories', 'description' => 'Melihat riwayat pegawai'],
            'employee_histories.create' => ['module' => 'employee_histories', 'description' => 'Membuat entri riwayat pegawai'],
            'documents.read' => ['module' => 'documents', 'description' => 'Melihat dokumen dan SK pegawai'],
            'documents.create' => ['module' => 'documents', 'description' => 'Mengunggah dokumen dan SK pegawai'],
            'documents.download' => ['module' => 'documents', 'description' => 'Mengunduh dokumen dan SK pegawai'],
            'leave_requests.read' => ['module' => 'leave_requests', 'description' => 'Melihat pengajuan cuti sesuai cakupan role'],
            'leave_requests.create' => ['module' => 'leave_requests', 'description' => 'Membuat pengajuan cuti'],
            'leave_requests.approve' => ['module' => 'leave_requests', 'description' => 'Memproses persetujuan pengajuan cuti'],
            'leave_balances.read' => ['module' => 'leave_balances', 'description' => 'Melihat saldo dan rekap cuti'],
            'leave_balances.update' => ['module' => 'leave_balances', 'description' => 'Mengelola saldo cuti pegawai'],
            'ews.read' => ['module' => 'ews', 'description' => 'Melihat peringatan dini pegawai'],
            'ews.update' => ['module' => 'ews', 'description' => 'Memperbarui tindak lanjut atau flag EWS'],
            'ews.configure' => ['module' => 'ews', 'description' => 'Mengatur parameter dan konfigurasi EWS'],
            'reports.export' => ['module' => 'reports', 'description' => 'Membuat dan mengekspor laporan'],
            'hari_libur.read' => ['module' => 'hari_libur', 'description' => 'Melihat hari libur dan cuti bersama'],
            'hari_libur.create' => ['module' => 'hari_libur', 'description' => 'Membuat hari libur dan cuti bersama'],
            'hari_libur.update' => ['module' => 'hari_libur', 'description' => 'Mengubah hari libur dan cuti bersama'],
            'hari_libur.delete' => ['module' => 'hari_libur', 'description' => 'Menghapus hari libur dan cuti bersama'],
            'audit_logs.read' => ['module' => 'audit_logs', 'description' => 'Melihat audit log sistem'],
            'notifications.read' => ['module' => 'notifications', 'description' => 'Melihat notifikasi milik sendiri'],
            'notifications.update' => ['module' => 'notifications', 'description' => 'Menandai notifikasi milik sendiri sudah dibaca'],
            'user_management.read' => ['module' => 'user_management', 'description' => 'Melihat pemetaan pengguna dan role'],
            'user_management.update' => ['module' => 'user_management', 'description' => 'Mengubah pemetaan pengguna dan role'],
            'rbac.read' => ['module' => 'rbac', 'description' => 'Melihat konfigurasi role dan permission'],
            'rbac.update' => ['module' => 'rbac', 'description' => 'Mengubah konfigurasi role dan permission'],
            'reference_data.manage' => ['module' => 'reference_data', 'description' => 'Mengelola tabel referensi dan data master'],
            'settings.manage' => ['module' => 'settings', 'description' => 'Mengelola pengaturan sistem'],
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
        // Konfigurasi sistem, user/role, referensi, hari libur, dan konfigurasi EWS
        // tetap eksklusif untuk super_admin sesuai PRD dan User Stories.
        $this->syncRolePermissions([
            'super_admin' => array_keys($permissions),
            'admin_kepegawaian' => [
                'dashboard.read',
                'employees.read',
                'employees.create',
                'employees.update',
                'employees.deactivate',
                'employees.restore',
                'employees.import',
                'employees.export',
                'employee_histories.read',
                'employee_histories.create',
                'documents.read',
                'documents.create',
                'documents.download',
                'leave_requests.read',
                'leave_balances.read',
                'leave_balances.update',
                'ews.read',
                'ews.update',
                'reports.export',
                'audit_logs.read',
                'notifications.read',
                'notifications.update',
            ],
            'pimpinan' => [
                'dashboard.read',
                'employees.read',
                'leave_requests.read',
                'leave_requests.approve',
                'ews.read',
                'reports.export',
                'notifications.read',
                'notifications.update',
            ],
            'atasan_langsung' => [
                'dashboard.read',
                'employees.read',
                'leave_requests.read',
                'leave_requests.approve',
                'notifications.read',
                'notifications.update',
            ],
            'pegawai' => [
                'dashboard.read',
                'leave_requests.read',
                'leave_requests.create',
                'leave_balances.read',
                'notifications.read',
                'notifications.update',
            ],
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
