<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name')->default('web');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('module');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->onDelete('cascade');
            $table->foreignId('permission_id')->constrained('permissions')->onDelete('cascade');
            $table->primary(['role_id', 'permission_id']);
        });

        // Seed Roles
        $roles = [
            ['id' => 1, 'name' => 'Super Admin', 'guard_name' => 'web', 'description' => 'Akses penuh ke semua konfigurasi sistem, user management, audit log, dan seluruh fitur operasional.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Admin Kepegawaian', 'guard_name' => 'web', 'description' => 'Mengelola data pegawai, riwayat, impor data, saldo & pengajuan cuti, serta dokumen kepegawaian.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Pimpinan', 'guard_name' => 'web', 'description' => 'Melihat dashboard data pegawai, menyetujui cuti tahap akhir (PYBMC), dan mengekspor laporan.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Atasan Langsung', 'guard_name' => 'web', 'description' => 'Melihat data bawahan langsung, menerima notifikasi cuti, serta memberikan persetujuan cuti tahap awal.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'Pegawai', 'guard_name' => 'web', 'description' => 'Mengakses data pribadi, mengajukan cuti, melihat saldo cuti, serta menerima notifikasi EWS pribadi.', 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('roles')->insert($roles);

        // Seed Permissions
        $permissions = [
            // Konfigurasi Sistem
            ['id' => 1, 'name' => 'manage_reference_tables', 'module' => 'Konfigurasi Sistem', 'description' => 'Kelola reference tables / tabel master data.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'configure_ews', 'module' => 'Konfigurasi Sistem', 'description' => 'Konfigurasi ambang batas EWS (Early Warning System).', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'manage_holidays', 'module' => 'Konfigurasi Sistem', 'description' => 'Kelola hari libur nasional.', 'created_at' => now(), 'updated_at' => now()],

            // User Management
            ['id' => 4, 'name' => 'manage_user_mapping', 'module' => 'User Management', 'description' => 'Kelola pemetaan email SSO Keycloak ke data pegawai.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'name' => 'manage_rbac', 'module' => 'User Management', 'description' => 'Kelola pemetaan role dan permission (akses fitur).', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'view_audit_log', 'module' => 'User Management', 'description' => 'Akses penuh ke halaman Audit Log.', 'created_at' => now(), 'updated_at' => now()],

            // Kepegawaian
            ['id' => 7, 'name' => 'view_all_pegawai', 'module' => 'Kepegawaian', 'description' => 'Melihat daftar dan profil seluruh pegawai.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8, 'name' => 'manage_pegawai', 'module' => 'Kepegawaian', 'description' => 'Tambah, ubah, dan nonaktifkan (soft delete) pegawai.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'name' => 'manage_riwayat', 'module' => 'Kepegawaian', 'description' => 'Kelola riwayat pangkat, jabatan, KGB, dan disiplin.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 10, 'name' => 'import_pegawai', 'module' => 'Kepegawaian', 'description' => 'Mengimpor data pegawai dari berkas Excel/CSV.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'name' => 'manage_supervisor', 'module' => 'Kepegawaian', 'description' => 'Mengatur supervisor / atasan langsung pegawai.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'name' => 'manage_documents', 'module' => 'Kepegawaian', 'description' => 'Upload dan kelola berkas/SK pegawai.', 'created_at' => now(), 'updated_at' => now()],

            // Cuti
            ['id' => 13, 'name' => 'apply_cuti', 'module' => 'Cuti', 'description' => 'Mengajukan cuti dan melihat sisa saldo pribadi.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 14, 'name' => 'view_all_cuti', 'module' => 'Cuti', 'description' => 'Melihat semua riwayat cuti dan mengelola saldo cuti pegawai.', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 15, 'name' => 'approve_cuti_stage1', 'module' => 'Cuti', 'description' => 'Memberikan persetujuan cuti tahap awal (Atasan langsung).', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 16, 'name' => 'approve_cuti_stage3', 'module' => 'Cuti', 'description' => 'Memberikan persetujuan cuti tahap akhir / PYBMC (Pimpinan).', 'created_at' => now(), 'updated_at' => now()],

            // EWS
            ['id' => 17, 'name' => 'view_all_ews', 'module' => 'EWS', 'description' => 'Melihat seluruh radar EWS dan merubah flag kinerja.', 'created_at' => now(), 'updated_at' => now()],

            // Laporan
            ['id' => 18, 'name' => 'generate_reports', 'module' => 'Laporan', 'description' => 'Mengunduh/export rekap data pegawai dan cuti dalam format Excel.', 'created_at' => now(), 'updated_at' => now()],
        ];
        DB::table('permissions')->insert($permissions);

        // Seed Role Permissions (Default Matrix)
        $rolePermissions = [];

        // 1. Super Admin (Mendapatkan semua permission ID 1-18)
        for ($i = 1; $i <= 18; $i++) {
            $rolePermissions[] = ['role_id' => 1, 'permission_id' => $i];
        }

        // 2. Admin Kepegawaian
        $adminKepegawaianPerms = [
            3, // manage_holidays
            7, 8, 9, 10, 11, 12, // Kepegawaian perms
            13, 14, // apply_cuti, view_all_cuti
            17, // view_all_ews
            18, // generate_reports
        ];
        foreach ($adminKepegawaianPerms as $pId) {
            $rolePermissions[] = ['role_id' => 2, 'permission_id' => $pId];
        }

        // 3. Pimpinan
        $pimpinanPerms = [
            7, // view_all_pegawai
            13, 16, // apply_cuti, approve_cuti_stage3
            18, // generate_reports
        ];
        foreach ($pimpinanPerms as $pId) {
            $rolePermissions[] = ['role_id' => 3, 'permission_id' => $pId];
        }

        // 4. Atasan Langsung
        $atasanLangsungPerms = [
            13, 15, // apply_cuti, approve_cuti_stage1
        ];
        foreach ($atasanLangsungPerms as $pId) {
            $rolePermissions[] = ['role_id' => 4, 'permission_id' => $pId];
        }

        // 5. Pegawai
        $pegawaiPerms = [
            13, // apply_cuti
        ];
        foreach ($pegawaiPerms as $pId) {
            $rolePermissions[] = ['role_id' => 5, 'permission_id' => $pId];
        }

        DB::table('role_permissions')->insert($rolePermissions);
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
