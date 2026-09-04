<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')
                ->where('name', 'users.switch_role')
                ->value('id');

            if ($permissionId === null) {
                return;
            }

            // Switch Role hanya boleh dimulai Super Admin dan Admin Kepegawaian.
            // Cabut dari pimpinan/kepala_bagian/pegawai pada data berjalan agar
            // permission yang salah terpasang tidak memberi akses simulasi.
            $roleIds = DB::table('roles')
                ->whereIn('name', ['pimpinan', 'kepala_bagian', 'pegawai'])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->delete();
            }
        });
    }

    public function down(): void
    {
        // Perubahan kebijakan RBAC tidak diputar balik otomatis agar rollback kode tidak memperluas akses tanpa keputusan baru.
    }
};
