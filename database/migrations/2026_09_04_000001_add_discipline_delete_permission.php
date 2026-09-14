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
            $permission = DB::table('permissions')->where('name', 'discipline_records.delete')->first();

            if ($permission === null) {
                $permissionId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'name' => 'discipline_records.delete',
                    'module' => 'discipline_records',
                    'description' => 'Menghapus riwayat hukuman disiplin',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $permissionId = $permission->id;
                DB::table('permissions')->where('id', $permissionId)->update([
                    'module' => 'discipline_records',
                    'description' => 'Menghapus riwayat hukuman disiplin',
                    'updated_at' => $now,
                ]);
            }

            $roleIds = DB::table('roles')->whereIn('name', ['super_admin', 'admin_kepegawaian'])->pluck('id');
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
        // Permission may have been customized; do not revoke automatically.
    }
};
