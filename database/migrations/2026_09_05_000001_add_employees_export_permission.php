<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $permission = DB::table('permissions')
                ->where('name', 'employees.export')
                ->first();

            if ($permission === null) {
                $permissionId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'name' => 'employees.export',
                    'module' => 'employees',
                    'description' => 'Mengekspor data pegawai (Excel/PDF)',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $permissionId = $permission->id;
                DB::table('permissions')->where('id', $permissionId)->update([
                    'module' => 'employees',
                    'description' => 'Mengekspor data pegawai (Excel/PDF)',
                    'updated_at' => $now,
                ]);
            }

            // Default hanya Super Admin dan Admin Kepegawaian; role lain
            // (pimpinan/kepala_bagian/pegawai) diatur manual oleh Super Admin via /rbac.
            $roleIds = DB::table('roles')
                ->whereIn('name', ['super_admin', 'admin_kepegawaian'])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        });
    }

    public function down(): void
    {
        // Permission dapat sudah disesuaikan operator; rollback tidak mencabut otomatis.
    }
};
