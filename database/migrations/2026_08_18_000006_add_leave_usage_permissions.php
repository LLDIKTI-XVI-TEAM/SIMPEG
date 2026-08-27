<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $permissions = [
                'cuti.balance.reconcile' => 'Melakukan rekonsiliasi fakta pemakaian dan saldo cuti',
                'cuti.manual.manage' => 'Mencatat, mengoreksi, dan membatalkan pemakaian cuti manual',
            ];
            $admin = Role::query()->where('name', 'admin_kepegawaian')->first();

            foreach ($permissions as $name => $description) {
                $permission = Permission::query()->updateOrCreate(
                    ['name' => $name],
                    ['module' => 'cuti', 'description' => $description],
                );

                // Permission mutasi fakta cuti sengaja eksklusif untuk Admin Kepegawaian.
                DB::table('role_permissions')->where('permission_id', $permission->id)->delete();

                if ($admin !== null) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $admin->id, 'permission_id' => $permission->id],
                        ['created_at' => now(), 'updated_at' => now()],
                    );
                }
            }
        });
    }

    public function down(): void
    {
        // Data permission dipertahankan agar rollback kode tidak mencabut otorisasi secara diam-diam.
    }
};
