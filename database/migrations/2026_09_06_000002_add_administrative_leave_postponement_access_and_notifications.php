<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Matriks default Admin Kepegawaian, sama dengan RbacSeeder. Dipakai hanya
     * ketika migrasi ini yang membuat role (fresh install): RbacSeeder bersikap
     * non-destruktif sehingga melewatkan permission yang sudah ada, dan tanpa
     * attach ini fresh install kekurangan mapping untuk permission yang dibuat
     * migrasi-migrasi sebelumnya.
     *
     * @var list<string>
     */
    private const ADMIN_DEFAULT_PERMISSIONS = [
        'employees.read',
        'employees.create',
        'employees.update',
        'employees.import',
        'employees.export',
        'employees.deactivate',
        'employees.restore',
        'employee_histories.read',
        'employee_histories.create',
        'employee_histories.update',
        'employee_histories.delete',
        'employee_histories.export',
        'discipline_records.read',
        'discipline_records.create',
        'discipline_records.delete',
        'employee_families.read',
        'employee_families.create',
        'employee_families.update',
        'employee_families.delete',
        'sk_requirements.manage',
        'audit_logs.read',
        'notifications.read',
        'notifications.update',
        'dokumen_sk.read',
        'dokumen_sk.create',
        'dokumen_sk.update',
        'dokumen_sk.delete',
        'ews.read',
        'cuti.create',
        'cuti.read_own',
        'cuti.read_all',
        'cuti.approve',
        'cuti.configure',
        'cuti.balance.read',
        'cuti.balance.reconcile',
        'cuti.manual.manage',
        'cuti.proof.generate',
        'cuti.cancellation.manage',
        'cuti.administrative_postponement.manage',
        'cuti.kepala_lembaga_documents.manage',
    ];

    /** Default pertama hanya Admin Kepegawaian; rerun tidak menimpa matrix atau policy operator. */
    public function up(): void
    {
        DB::transaction(function (): void {
            Permission::query()->firstOrCreate(
                ['name' => 'cuti.administrative_postponement.manage'],
                ['module' => 'cuti', 'description' => 'Menangguhkan cuti yang disetujui secara administratif'],
            );
            $admin = Role::query()->firstOrCreate(
                ['name' => 'admin_kepegawaian'],
                ['guard_name' => 'web', 'description' => 'Admin Kepegawaian'],
            );

            if ($admin->wasRecentlyCreated) {
                $admin->permissions()->syncWithoutDetaching(
                    Permission::query()->whereIn('name', self::ADMIN_DEFAULT_PERMISSIONS)->pluck('id')->all()
                );
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
