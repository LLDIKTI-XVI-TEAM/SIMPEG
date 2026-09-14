<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Provisioning deploy-time untuk database yang tidak menjalankan seeder ulang. */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $permission = DB::table('permissions')->where('name', 'ews.configure')->first();
            $permissionId = $permission?->id ?? (string) Str::uuid();

            if ($permission === null) {
                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'name' => 'ews.configure',
                    'module' => 'ews',
                    'description' => 'Mengonfigurasi parameter dan threshold EWS',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('permissions')->where('id', $permissionId)->update([
                    'module' => 'ews',
                    'description' => 'Mengonfigurasi parameter dan threshold EWS',
                    'updated_at' => $now,
                ]);
            }

            // Default konfigurasi hanya diberikan ke Super Admin; operator dapat
            // mendelegasikannya kemudian melalui matriks RBAC.
            $roleIds = DB::table('roles')->where('name', 'super_admin')->pluck('id');
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
        // Jangan mencabut konfigurasi yang mungkin telah diatur operator.
    }
};
