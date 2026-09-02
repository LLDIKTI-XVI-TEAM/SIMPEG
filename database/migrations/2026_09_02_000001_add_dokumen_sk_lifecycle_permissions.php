<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var array<string, array{module: string, description: string}> */
    private const PERMISSIONS = [
        'dokumen_sk.create' => [
            'module' => 'dokumen_sk',
            'description' => 'Mengunggah dokumen dan SK pegawai (riwayat, status, pengangkatan, berkas tambahan)',
        ],
        'dokumen_sk.update' => [
            'module' => 'dokumen_sk',
            'description' => 'Mengganti berkas SK riwayat dan memperbarui berkas tambahan pegawai',
        ],
        'dokumen_sk.delete' => [
            'module' => 'dokumen_sk',
            'description' => 'Menghapus berkas tambahan mandiri pegawai (KTP, KK, ijazah, lainnya)',
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            foreach (self::PERMISSIONS as $name => $attributes) {
                $existing = DB::table('permissions')->where('name', $name)->first();
                $permissionId = $existing?->id ?? (string) Str::uuid();

                if ($existing === null) {
                    DB::table('permissions')->insert([
                        'id' => $permissionId,
                        'name' => $name,
                        'module' => $attributes['module'],
                        'description' => $attributes['description'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    DB::table('permissions')->where('id', $permissionId)->update([
                        'module' => $attributes['module'],
                        'description' => $attributes['description'],
                        'updated_at' => $now,
                    ]);
                }
            }

            // Sinkronkan ke role yang memang mengelola dokumen
            $rolePermissions = [
                'super_admin' => array_keys(self::PERMISSIONS),
                'admin_kepegawaian' => array_keys(self::PERMISSIONS),
            ];

            foreach ($rolePermissions as $roleName => $permissionNames) {
                $roleId = DB::table('roles')->where('name', $roleName)->value('id');
                if ($roleId === null) {
                    continue;
                }

                foreach ($permissionNames as $permissionName) {
                    $permissionId = DB::table('permissions')->where('name', $permissionName)->value('id');
                    if ($permissionId === null) {
                        continue;
                    }

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
        // Permission lifecycle dokumen dapat sudah disesuaikan operator; rollback tidak mencabut otomatis.
    }
};
