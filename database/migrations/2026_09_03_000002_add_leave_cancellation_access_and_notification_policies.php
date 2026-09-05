<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $cancellationEvents = [
        'cuti.pembatalan_diajukan',
        'cuti.pembatalan_disetujui',
        'cuti.pembatalan_ditolak',
    ];

    /** @var list<string> */
    private array $auditEventsBeforeCancellation = [
        'CREATE', 'UPDATE', 'DELETE', 'SOFT_DELETE', 'RESTORE', 'LOGIN', 'LOGOUT', 'APPROVE', 'POSTPONE', 'IMPORT', 'SESSION_TIMEOUT',
        'LEAVE_BALANCE_OPENING_SET', 'LEAVE_BALANCE_CORRECTED', 'LEAVE_ROLLOVER_APPLIED', 'LEAVE_BALANCE_DEDUCTED', 'LEAVE_PROOF_GENERATED',
        'CONFIG_UPDATE', 'LEAVE_BALANCE_RESERVED', 'LEAVE_BALANCE_RESERVATION_ADJUSTED', 'LEAVE_BALANCE_RESERVATION_CONVERTED',
        'LEAVE_BALANCE_RESERVATION_RELEASED', 'DUTY_POSTPONEMENT', 'VERIFY', 'DECIDE', 'CHANGE_REQUESTED', 'DEFER', 'NOT_APPROVED',
        'SWITCH_ROLE', 'REVERT_ROLE', 'ROLE_SIMULATION_USAGE',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $permission = Permission::query()->updateOrCreate(
                ['name' => 'cuti.cancellation.manage'],
                [
                    'module' => 'cuti',
                    'description' => 'Memutuskan permohonan pembatalan cuti',
                ],
            );
            $admin = Role::query()->where('name', 'admin_kepegawaian')->first();

            // Keputusan pembatalan adalah kewenangan eksklusif Admin Kepegawaian.
            DB::table('role_permissions')->where('permission_id', $permission->id)->delete();
            if ($admin !== null) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $admin->id, 'permission_id' => $permission->id],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }

            $channelIds = DB::table('ref_notification_channels')
                ->whereIn('code', ['in_app', 'email'])
                ->pluck('id', 'code');

            foreach (['in_app', 'email'] as $channelCode) {
                if (! $channelIds->has($channelCode)) {
                    throw new RuntimeException("Migrasi pembatalan cuti gagal: channel {$channelCode} tidak ditemukan.");
                }
            }

            foreach ($this->cancellationEvents as $eventKey) {
                foreach (['in_app', 'email'] as $channelCode) {
                    DB::table('notification_event_channels')->insertOrIgnore([
                        [
                            'event_key' => $eventKey,
                            'notification_channel_id' => $channelIds[$channelCode],
                            'id' => (string) Str::uuid(),
                            'is_enabled' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ],
                    ]);
                }
            }

            // Migration historis tetap immutable; hanya kebijakan runtime aktif yang dicabut.
            DB::table('notification_event_channels')->where('event_key', 'cuti.perlu_perubahan')->delete();

            $this->applyAuditConstraint([
                ...$this->auditEventsBeforeCancellation,
                'LEAVE_CANCELLATION_REQUESTED',
                'LEAVE_CANCELLATION_APPROVED',
                'LEAVE_CANCELLATION_REJECTED',
            ]);
        });
    }

    public function down(): void
    {
        if (DB::table('audit_logs')
            ->whereIn('event', [
                'LEAVE_CANCELLATION_REQUESTED',
                'LEAVE_CANCELLATION_APPROVED',
                'LEAVE_CANCELLATION_REJECTED',
            ])
            ->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena audit permohonan pembatalan cuti sudah tercatat.');
        }

        // Rollback kode memerlukan kembali policy bawaan event lama, tanpa menimpa konfigurasi yang sudah ada.
        $channelIds = DB::table('ref_notification_channels')
            ->whereIn('code', ['in_app', 'email'])
            ->pluck('id', 'code');
        if ($channelIds->count() !== 2) {
            throw new RuntimeException('Rollback pembatalan cuti gagal: channel in_app dan email harus tersedia.');
        }

        $now = now();
        foreach ($channelIds as $channelId) {
            DB::table('notification_event_channels')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'event_key' => 'cuti.perlu_perubahan',
                'notification_channel_id' => $channelId,
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->applyAuditConstraint($this->auditEventsBeforeCancellation);
        // Permission/policy mungkin telah dikonfigurasi operator setelah deploy; rollback kode tidak mencabutnya diam-diam.
    }

    /** @param list<string> $events */
    private function applyAuditConstraint(array $events): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $quotedEvents = collect($events)->map(fn (string $event): string => "'{$event}'")->implode(', ');
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");
    }
};
