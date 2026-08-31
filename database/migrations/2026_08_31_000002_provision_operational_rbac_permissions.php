<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Permission operasional yang harus tersedia segera setelah deployment. */
    private const PERMISSIONS = [
        'employee_histories.export' => [
            'module' => 'employee_histories',
            'description' => 'Melihat dan mengunduh Laporan Riwayat Kepangkatan pegawai',
        ],
        'dokumen_sk.read' => [
            'module' => 'dokumen_sk',
            'description' => 'Melihat dokumen dan SK pegawai',
        ],
        'ews.read' => [
            'module' => 'ews',
            'description' => 'Melihat daftar EWS aktif seluruh pegawai',
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $roleIds = DB::table('roles')
                ->whereIn('name', ['super_admin', 'admin_kepegawaian', 'pimpinan'])
                ->pluck('id');

            foreach (self::PERMISSIONS as $name => $attributes) {
                $existing = DB::table('permissions')->where('name', $name)->first();
                $permissionId = $existing?->id ?? (string) Str::uuid();

                if ($existing === null) {
                    DB::table('permissions')->insert([
                        'id' => $permissionId,
                        'name' => $name,
                        ...$attributes,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('permissions')->where('id', $permissionId)->update([
                        ...$attributes,
                        'updated_at' => $now,
                    ]);
                }

                foreach ($roleIds as $roleId) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $permissionId],
                        ['created_at' => $now, 'updated_at' => $now],
                    );
                }
            }
        });
    }

    public function down(): void
    {
        // Hak akses dapat sudah disesuaikan operator setelah deployment; jangan cabut saat rollback.
    }
};
