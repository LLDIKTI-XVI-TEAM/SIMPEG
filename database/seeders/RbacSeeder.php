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
            'super_admin' => 'Super Admin — akses administrasi penuh selain pengajuan cuti pribadi',
            'admin_kepegawaian' => 'Admin Kepegawaian — kelola data pegawai, pantau cuti, dan ajukan cuti sendiri',
            'pimpinan' => 'Pimpinan — dashboard, pemantauan, final approval, dan pengajuan cuti sendiri',
            'kepala_bagian' => 'Kepala Bagian — approval cuti bawahan dan pengajuan cuti sendiri',
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
            'employee_families.read' => ['module' => 'employee_families', 'description' => 'Melihat data keluarga pegawai'],
            'employee_families.create' => ['module' => 'employee_families', 'description' => 'Membuat data keluarga pegawai'],
            'employee_families.update' => ['module' => 'employee_families', 'description' => 'Mengubah data keluarga pegawai'],
            'employee_families.delete' => ['module' => 'employee_families', 'description' => 'Menonaktifkan data keluarga pegawai'],
            'hari_libur.read' => ['module' => 'hari_libur', 'description' => 'Melihat hari libur dan cuti bersama'],
            'hari_libur.create' => ['module' => 'hari_libur', 'description' => 'Membuat hari libur dan cuti bersama'],
            'hari_libur.update' => ['module' => 'hari_libur', 'description' => 'Mengubah hari libur dan cuti bersama'],
            'hari_libur.delete' => ['module' => 'hari_libur', 'description' => 'Menghapus hari libur dan cuti bersama'],
            'reference_tables.manage' => ['module' => 'reference_tables', 'description' => 'Mengelola data referensi SIMPEG'],
            'sk_requirements.manage' => ['module' => 'sk_requirements', 'description' => 'Mengelola matriks SK wajib per jenis pegawai'],
            'audit_logs.read' => ['module' => 'audit_logs', 'description' => 'Melihat audit log sistem'],
            'notifications.read' => ['module' => 'notifications', 'description' => 'Melihat notifikasi milik sendiri'],
            'notifications.update' => ['module' => 'notifications', 'description' => 'Menandai notifikasi milik sendiri sudah dibaca'],
            'users.switch_role' => ['module' => 'users', 'description' => 'Melakukan simulasi beralih ke role yang lebih rendah untuk demo/testing/support'],
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
            'cuti.balance.reconcile' => ['module' => 'cuti', 'description' => 'Mencatat dan memperbaiki fakta pemakaian serta saldo cuti'],
            'cuti.manual.manage' => ['module' => 'cuti', 'description' => 'Mencatat, mengoreksi, dan membatalkan pemakaian cuti manual'],
            'cuti.proof.generate' => ['module' => 'cuti', 'description' => 'Membuat bukti/formulir cuti resmi setelah approval final'],
            'cuti.kepala_lembaga_documents.manage' => ['module' => 'cuti', 'description' => 'Mengelola dokumen pendukung cuti Kepala Lembaga'],
        ];

        foreach ($roles as $name => $description) {
            Role::updateOrCreate(['name' => $name], [
                'guard_name' => 'web',
                'description' => $description,
            ]);
        }

        foreach ($permissions as $name => $attributes) {
            Permission::updateOrCreate(['name' => $name], $attributes);
        }

        // Mapping permission per role dibuat eksplisit agar perubahan hak akses mudah ditelusuri saat review.
        // hari_libur tetap khusus super_admin; pimpinan/atasan/pegawai belum punya akses route admin.
        // Catatan cuti: cuti.approve_stage2 (Kabag Umum) belum dipetakan ke role dasar karena approver stage 2
        // bersifat person-based via approval_configs; pemetaan role penampungnya menunggu konfirmasi dan ditegakkan
        // di approval engine. cuti.configure dibatasi khusus super_admin.
        $this->syncRolePermissions([
            'super_admin' => array_values(array_diff(array_keys($permissions), [
                'cuti.create',
                'cuti.balance.reconcile',
                'cuti.manual.manage',
            ])),
            'admin_kepegawaian' => [
                'employees.read',
                'employees.create',
                'employees.update',
                'employees.import',
                'employees.deactivate',
                // K-STATUS-04: reaktivasi boleh Super Admin ATAU Admin Kepegawaian
                // selama role EFEKTIF memiliki employees.restore. Gate tetap memakai
                // role efektif sehingga simulasi tidak dibypass oleh role asli.
                'employees.restore',
                'employee_histories.read',
                'employee_histories.create',
                'employee_histories.update',
                'employee_histories.delete',
                'discipline_records.read',
                'discipline_records.create',
                'employee_families.read',
                'employee_families.create',
                'employee_families.update',
                'employee_families.delete',
                'sk_requirements.manage',
                'audit_logs.read',
                'notifications.read',
                'notifications.update',
                // Admin kepegawaian dapat mengajukan cuti sendiri dan memonitor seluruh pengajuan tanpa menyetujui.
                'cuti.create',
                'cuti.read_all',
                'cuti.balance.read',
                'cuti.balance.reconcile',
                'cuti.manual.manage',
                'cuti.kepala_lembaga_documents.manage',
            ],
            'pimpinan' => [
                'employees.read',
                'notifications.read',
                'notifications.update',
                // Role pimpinan dapat mengajukan cuti sendiri bila bukan pegawai bertanda Kepala Lembaga.
                'cuti.create',
                'cuti.approve',
                'cuti.approve_stage3',
                'cuti.read_all',
            ],
            'kepala_bagian' => [
                'notifications.read',
                'notifications.update',
                // Kepala bagian dapat mengajukan cuti sendiri sekaligus memegang approval stage 1 bawahan.
                'cuti.create',
                'cuti.approve',
                'cuti.approve_stage1',
            ],
            'pegawai' => [
                'employees.read_self',
                'notifications.read',
                'notifications.update',
                // Pegawai dapat membuat pengajuan cuti miliknya sendiri.
                'cuti.create',
                // Profil mandiri hanya memberi akses baca; mutasi tetap melalui admin kepegawaian.
                'employee_families.read',
                'employee_histories.read',
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
