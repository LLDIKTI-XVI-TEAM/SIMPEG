<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * cuti.balance.read bukan capability PATEN global: endpoint saldo pribadi
     * tetap berbasis ownership, sementara pembacaan administrasi pegawai lain
     * membutuhkan grant ini dan kemudian dibatasi canonical employee scope.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->firstOrCreate(
                ['name' => 'cuti.balance.read'],
                ['module' => 'cuti', 'description' => 'Melihat saldo cuti pegawai dalam scope yang diizinkan'],
            );

            // Default produk tetap memberi kemampuan baca saldo kepada semua role.
            // Ini dijalankan sekali sebagai perubahan kebijakan; seeder berikutnya
            // tidak lagi menimpa keputusan grant/revoke operator.
            Role::query()
                ->whereIn('name', ['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'])
                ->get()
                ->each(fn (Role $role) => $role->permissions()->syncWithoutDetaching([$permission->id]));
        });
    }

    public function down(): void
    {
        // Grant operator tidak dicabut otomatis ketika rollback kode.
    }
};
