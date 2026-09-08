<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Default pertama hanya Admin Kepegawaian; rerun tidak menimpa matrix atau policy operator. */
    public function up(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->firstOrCreate(
                ['name' => 'cuti.administrative_postponement.manage'],
                ['module' => 'cuti', 'description' => 'Menangguhkan cuti yang disetujui secara administratif'],
            );
            if ($permission->wasRecentlyCreated) {
                $admin = Role::query()->firstOrCreate(
                    ['name' => 'admin_kepegawaian'],
                    ['guard_name' => 'web', 'description' => 'Admin Kepegawaian'],
                );
                $admin->permissions()->syncWithoutDetaching([$permission->id]);
            }

            $channelIds = DB::table('ref_notification_channels')->whereIn('code', ['in_app', 'email'])->pluck('id', 'code');
            if ($channelIds->count() !== 2) {
                throw new RuntimeException('Migrasi penangguhan administratif memerlukan channel in_app dan email.');
            }
            $now = now();
            foreach ($channelIds as $channelId) {
                DB::table('notification_event_channels')->insertOrIgnore([
                    'id' => (string) Str::uuid(), 'event_key' => 'cuti.ditangguhkan_administratif',
                    'notification_channel_id' => $channelId, 'is_enabled' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }

    /** Rollback kode tidak mencabut permission/policy yang mungkin telah disesuaikan operator. */
    public function down(): void
    {
        if (DB::table('leave_requests')->where('status', 'ditangguhkan_administratif')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena keputusan penangguhan administratif sudah tercatat.');
        }
    }
};
