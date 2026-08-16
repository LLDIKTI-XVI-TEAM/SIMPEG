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
                ->where('name', 'reference_tables.manage')
                ->first();

            if ($permission === null) {
                $permissionId = (string) Str::uuid();

                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'name' => 'reference_tables.manage',
                    'module' => 'reference_tables',
                    'description' => 'Mengelola data referensi SIMPEG',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $permissionId = $permission->id;

                DB::table('permissions')
                    ->where('id', $permissionId)
                    ->update([
                        'module' => 'reference_tables',
                        'description' => 'Mengelola data referensi SIMPEG',
                        'updated_at' => $now,
                    ]);
            }

            $superAdminId = DB::table('roles')
                ->where('name', 'super_admin')
                ->value('id');

            if ($superAdminId === null) {
                return;
            }

            // Database existing sudah memiliki role; pasang pivot tanpa bergantung pada seeder deploy.
            DB::table('role_permissions')->updateOrInsert(
                [
                    'role_id' => $superAdminId,
                    'permission_id' => $permissionId,
                ],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        });
    }

    public function down(): void
    {
        // Data permission dapat sudah ada atau telah diubah operator sebelum migrasi ini.
        // Tanpa catatan kepemilikan, rollback tidak boleh mencabut permission maupun pemetaan role.
    }
};
