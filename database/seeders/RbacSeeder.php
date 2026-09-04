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
            'super_admin' => 'Super Admin — akses administrasi penuh dan fitur cuti pegawai',
            'admin_kepegawaian' => 'Admin Kepegawaian — kelola data pegawai, pantau cuti, dan ajukan cuti sendiri',
            'pimpinan' => 'Pimpinan — dashboard, pemantauan, final approval, tanpa pengajuan cuti pribadi',
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
            'employees.export' => ['module' => 'employees', 'description' => 'Mengekspor data pegawai (Excel/PDF)'],
            'employees.read_self' => ['module' => 'employees', 'description' => 'Melihat detail data pegawai milik sendiri'],
            'employee_histories.read' => ['module' => 'employee_histories', 'description' => 'Melihat riwayat pegawai (pangkat, jabatan, KGB, pendidikan, pengangkatan, status kepegawaian)'],
            'employee_histories.create' => ['module' => 'employee_histories', 'description' => 'Membuat entri riwayat pegawai'],
            'employee_histories.update' => ['module' => 'employee_histories', 'description' => 'Memperbarui riwayat pegawai'],
            'employee_histories.delete' => ['module' => 'employee_histories', 'description' => 'Menghapus riwayat pegawai'],
            'employee_histories.export' => ['module' => 'employee_histories', 'description' => 'Melihat dan mengunduh Laporan Riwayat Kepangkatan pegawai'],
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
            'reference_tables.manage' => ['module' => 'reference_tables', 'description' => 'Mengelola data referensi SIMPEG'],
            'sk_requirements.manage' => ['module' => 'sk_requirements', 'description' => 'Mengelola matriks SK wajib per jenis pegawai'],
            'audit_logs.read' => ['module' => 'audit_logs', 'description' => 'Melihat audit log sistem'],
            'notifications.read' => ['module' => 'notifications', 'description' => 'Melihat notifikasi milik sendiri'],
            'notifications.update' => ['module' => 'notifications', 'description' => 'Menandai notifikasi milik sendiri sudah dibaca'],
            'users.switch_role' => ['module' => 'users', 'description' => 'Melakukan simulasi beralih ke role yang lebih rendah untuk demo/testing/support'],
            // Permission cuti menjadi gerbang halaman/fitur; otorisasi keputusan tetap berbasis
            // approver yang tercatat pada approval chain aktif, bukan permission per stage.
            'cuti.create' => ['module' => 'cuti', 'description' => 'Mengajukan permohonan cuti'],
            'cuti.read_own' => ['module' => 'cuti', 'description' => 'Melihat pengajuan cuti milik sendiri'],
            'cuti.read_all' => ['module' => 'cuti', 'description' => 'Melihat seluruh pengajuan cuti (monitor)'],
            'cuti.approve' => ['module' => 'cuti', 'description' => 'Mengambil keputusan approval cuti sesuai assignment aktif'],
            'cuti.configure' => ['module' => 'cuti', 'description' => 'Mengonfigurasi approval chain cuti'],
            'cuti.balance.read' => ['module' => 'cuti', 'description' => 'Melihat saldo cuti'],
            'cuti.balance.reconcile' => ['module' => 'cuti', 'description' => 'Mencatat dan memperbaiki fakta pemakaian serta saldo cuti'],
            'cuti.manual.manage' => ['module' => 'cuti', 'description' => 'Mencatat, mengoreksi, dan membatalkan pemakaian cuti manual'],
            'cuti.proof.generate' => ['module' => 'cuti', 'description' => 'Membuat ulang bukti/formulir cuti resmi setelah approval final'],
            'cuti.kepala_lembaga_documents.manage' => ['module' => 'cuti', 'description' => 'Mengelola dokumen pendukung cuti Kepala Lembaga'],
            'dokumen_sk.read' => ['module' => 'dokumen_sk', 'description' => 'Melihat dokumen dan SK pegawai'],
            'dokumen_sk.create' => ['module' => 'dokumen_sk', 'description' => 'Mengunggah dokumen dan SK pegawai (riwayat, status, pengangkatan, berkas tambahan)'],
            'dokumen_sk.update' => ['module' => 'dokumen_sk', 'description' => 'Mengganti berkas SK riwayat dan memperbarui berkas tambahan pegawai'],
            'dokumen_sk.delete' => ['module' => 'dokumen_sk', 'description' => 'Menghapus berkas tambahan mandiri pegawai (KTP, KK, ijazah, lainnya)'],
            'ews.read' => ['module' => 'ews', 'description' => 'Melihat daftar EWS aktif seluruh pegawai'],
            'ews.configure' => ['module' => 'ews', 'description' => 'Mengonfigurasi parameter dan threshold EWS'],
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
        // Tahap approval tidak disimpan sebagai permission: semua role dapat menjadi approver bila tercatat
        // pada chain aktif. Pimpinan sengaja tidak menerima hak pengajuan cuti.
        $this->syncRolePermissions([
            'super_admin' => array_keys($permissions),
            'admin_kepegawaian' => [
                'employees.read',
                'employees.create',
                'employees.update',
                'employees.import',
                'employees.export',
                'employees.deactivate',
                // K-STATUS-04: reaktivasi boleh Super Admin ATAU Admin Kepegawaian
                // selama role EFEKTIF memiliki employees.restore. Gate tetap memakai
                // role efektif sehingga simulasi tidak dibypass oleh role asli.
                'employees.restore',
                'employee_histories.read',
                'employee_histories.create',
                'employee_histories.update',
                'employee_histories.delete',
                'employee_histories.export',
                'discipline_records.read',
                'discipline_records.create',
                'discipline_records.delete',
                'employee_families.read',
                'employee_families.create',
                'employee_families.update',
                'employee_families.delete',
                'sk_requirements.manage',
                'audit_logs.read',
                'notifications.read',
                'notifications.update',
                // Admin kepegawaian dapat melihat dokumen & SK pegawai dan memantau EWS aktif
                'dokumen_sk.read',
                'dokumen_sk.create',
                'dokumen_sk.update',
                'dokumen_sk.delete',
                'ews.read',
                // Admin kepegawaian dapat mengajukan, memantau, mengelola konfigurasi, dan administrasi cuti.
                'cuti.create',
                'cuti.read_own',
                'cuti.read_all',
                'cuti.approve',
                'cuti.configure',
                'cuti.balance.read',
                'cuti.balance.reconcile',
                'cuti.manual.manage',
                'cuti.proof.generate',
                'cuti.kepala_lembaga_documents.manage',
            ],
            'pimpinan' => [
                'employees.read',
                'employee_histories.read',
                'employee_histories.export',
                'employee_families.read',
                'discipline_records.read',
                'dokumen_sk.read',
                'ews.read',
                'notifications.read',
                'notifications.update',
                // Pimpinan dapat memantau dan mengatur chain, tetapi tidak mengajukan cuti sendiri.
                'cuti.read_own',
                'cuti.approve',
                'cuti.read_all',
                'cuti.configure',
                'cuti.balance.read',
            ],
            'kepala_bagian' => [
                'notifications.read',
                'notifications.update',
                // Kepala Bagian dapat mengajukan cuti, memantau, dan mengatur chain.
                'cuti.create',
                'cuti.read_own',
                'cuti.approve',
                'cuti.read_all',
                'cuti.configure',
                'cuti.balance.read',
            ],
            'pegawai' => [
                'employees.read_self',
                'notifications.read',
                'notifications.update',
                // Pegawai dapat membuat serta membaca pengajuan/saldo miliknya sendiri.
                'cuti.create',
                'cuti.read_own',
                'cuti.approve',
                'cuti.balance.read',
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
