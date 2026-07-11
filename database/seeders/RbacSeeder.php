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
            'kepala_bagian' => 'Kepala Bagian — approval stage 1 cuti, read-only data bawahan',
            'pegawai' => 'Pegawai — read-only data sendiri, ajukan cuti, lihat notifikasi',
        ];

        // Permission level aksi memakai konvensi module.action agar mudah diaudit dan diperluas.
        $permissions = [
            'employees.read' => ['module' => 'employees', 'description' => 'Melihat daftar data pegawai'],
            'employees.create' => ['module' => 'employees', 'description' => 'Membuat data pegawai'],
            'employees.update' => ['module' => 'employees', 'description' => 'Mengubah data pegawai'],
            'employees.import' => ['module' => 'employees', 'description' => 'Import data pegawai'],
            'employees.deactivate' => ['module' => 'employees', 'description' => 'Menonaktifkan data pegawai'],
            'employees.restore' => ['module' => 'employees', 'description' => 'Mengaktifkan kembali data pegawai nonaktif'],
            'employees.read_self' => ['module' => 'employees', 'description' => 'Melihat detail data pegawai milik sendiri'],
            'employee_histories.read' => ['module' => 'employee_histories', 'description' => 'Melihat riwayat pegawai'],
            'employee_histories.create' => ['module' => 'employee_histories', 'description' => 'Membuat entri riwayat pegawai'],
            'employee_histories.update' => ['module' => 'employee_histories', 'description' => 'Memperbarui riwayat pegawai'],
            'employee_histories.delete' => ['module' => 'employee_histories', 'description' => 'Menghapus riwayat pegawai'],
            'discipline_records.read' => ['module' => 'discipline_records', 'description' => 'Melihat riwayat hukuman disiplin'],
            'discipline_records.create' => ['module' => 'discipline_records', 'description' => 'Membuat riwayat hukuman disiplin'],
            'discipline_records.delete' => ['module' => 'discipline_records', 'description' => 'Menghapus riwayat hukuman disiplin'],
            'employee_families.read' => ['module' => 'employee_families', 'description' => 'Melihat data keluarga pegawai'],
            'employee_families.create' => ['module' => 'employee_families', 'description' => 'Membuat data keluarga pegawai'],
            'employee_families.update' => ['module' => 'employee_families', 'description' => 'Mengubah data keluarga pegawai'],
            'employee_families.delete' => ['module' => 'employee_families', 'description' => 'Menonaktifkan data keluarga pegawai'],
            'hari_libur.read' => ['module' => 'hari_libur', 'description' => 'Melihat hari libur dan cuti bersama'],
            'hari_libur.create' => ['module' => 'hari_libur', 'description' => 'Membuat hari libur dan cuti bersama'],
            'hari_libur.update' => ['module' => 'hari_libur', 'description' => 'Mengubah hari libur dan cuti bersama'],
            'hari_libur.delete' => ['module' => 'hari_libur', 'description' => 'Menghapus hari libur dan cuti bersama'],
            'audit_logs.read' => ['module' => 'audit_logs', 'description' => 'Melihat audit log sistem'],
            'notifications.read' => ['module' => 'notifications', 'description' => 'Melihat notifikasi milik sendiri'],
            'notifications.update' => ['module' => 'notifications', 'description' => 'Menandai notifikasi milik sendiri sudah dibaca'],
            // Permission cuti menjadi gerbang kasar route/menu; otorisasi inti per pengajuan tetap berbasis approver terkonfigurasi.
            'cuti.create' => ['module' => 'cuti', 'description' => 'Mengajukan permohonan cuti'],
            'cuti.read_own' => ['module' => 'cuti', 'description' => 'Melihat pengajuan cuti milik sendiri'],
            'cuti.read_all' => ['module' => 'cuti', 'description' => 'Melihat seluruh pengajuan cuti (monitor)'],
            'cuti.approve' => ['module' => 'cuti', 'description' => 'Mengambil keputusan approval cuti sesuai assignment aktif'],
            'cuti.approve_stage1' => ['module' => 'cuti', 'description' => 'Menyetujui/menunda cuti pada stage 1 (Atasan Langsung)'],
            'cuti.approve_stage2' => ['module' => 'cuti', 'description' => 'Menyetujui/menunda cuti pada stage 2 (Kabag Umum)'],
            'cuti.approve_stage3' => ['module' => 'cuti', 'description' => 'Menyetujui/menunda cuti pada stage 3 (Pimpinan/PYBMC)'],
            'cuti.configure' => ['module' => 'cuti', 'description' => 'Mengonfigurasi approval chain cuti'],
            'cuti.configure_chain' => ['module' => 'cuti', 'description' => 'Mengonfigurasi rantai approval cuti per pegawai'],
            'cuti.balance.read' => ['module' => 'cuti', 'description' => 'Melihat saldo cuti'],
            'cuti.balance.adjust' => ['module' => 'cuti', 'description' => 'Melakukan koreksi saldo cuti yang diaudit'],
            'cuti.proof.generate' => ['module' => 'cuti', 'description' => 'Membuat bukti/formulir cuti resmi setelah approval final'],
            'cuti.kepala_lembaga_documents.manage' => ['module' => 'cuti', 'description' => 'Mengelola dokumen pendukung cuti Kepala Lembaga'],
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
        // Catatan cuti: cuti.approve_stage2 (Kabag Umum) belum dipetakan ke role dasar karena approver stage 2
        // bersifat person-based via approval_configs; pemetaan role penampungnya menunggu konfirmasi dan ditegakkan
        // di approval engine. cuti.configure dibatasi khusus super_admin.
        $this->syncRolePermissions([
            'super_admin' => array_keys($permissions),
            'admin_kepegawaian' => [
                'employees.read',
                'employees.create',
                'employees.update',
                'employees.import',
                'employees.deactivate',
                'employees.restore',
                'employee_histories.read',
                'employee_histories.create',
                'employee_histories.update',
                'employee_histories.delete',
                'discipline_records.read',
                'discipline_records.create',
                'discipline_records.delete',
                'employee_families.read',
                'employee_families.create',
                'employee_families.update',
                'employee_families.delete',
                'audit_logs.read',
                'notifications.read',
                'notifications.update',
                // Admin kepegawaian memonitor seluruh pengajuan cuti namun tidak boleh menyetujui.
                'cuti.read_all',
                'cuti.balance.read',
                'cuti.balance.adjust',
                'cuti.kepala_lembaga_documents.manage',
            ],
            'pimpinan' => [
                'notifications.read',
                'notifications.update',
                // Pimpinan/PYBMC adalah approver final sekaligus dapat memonitor seluruh pengajuan.
                'cuti.approve',
                'cuti.approve_stage3',
                'cuti.read_all',
            ],
            'kepala_bagian' => [
                'notifications.read',
                'notifications.update',
                // Kepala bagian memegang approval stage 1 atas pengajuan bawahannya.
                'cuti.approve',
                'cuti.approve_stage1',
            ],
            'pegawai' => [
                'employees.read_self',
                'notifications.read',
                'notifications.update',
                // Pegawai sebagai pemohon hanya boleh membuat pengajuan cuti.
                'cuti.create',
                // Pegawai dapat melihat, menambah, dan mengedit data keluarga miliknya sendiri.
                // Otorisasi "hanya milik sendiri" dijaga di layer controller dan FormRequest.
                'employee_families.read',
                'employee_families.create',
                'employee_families.update',
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
