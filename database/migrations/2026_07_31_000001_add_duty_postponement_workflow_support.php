<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private array $auditEventsBeforeDutyPostponement = [
        'CREATE',
        'UPDATE',
        'DELETE',
        'SOFT_DELETE',
        'RESTORE',
        'LOGIN',
        'LOGOUT',
        'APPROVE',
        'POSTPONE',
        'IMPORT',
        'SESSION_TIMEOUT',
        'LEAVE_BALANCE_OPENING_SET',
        'LEAVE_BALANCE_CORRECTED',
        'LEAVE_ROLLOVER_APPLIED',
        'LEAVE_BALANCE_DEDUCTED',
        'LEAVE_PROOF_GENERATED',
        'CONFIG_UPDATE',
        'LEAVE_BALANCE_RESERVED',
        'LEAVE_BALANCE_RESERVATION_ADJUSTED',
        'LEAVE_BALANCE_RESERVATION_CONVERTED',
        'LEAVE_BALANCE_RESERVATION_RELEASED',
    ];

    /** @var list<string> */
    private array $auditEventsWithDutyPostponement = [
        'CREATE',
        'UPDATE',
        'DELETE',
        'SOFT_DELETE',
        'RESTORE',
        'LOGIN',
        'LOGOUT',
        'APPROVE',
        'POSTPONE',
        'IMPORT',
        'SESSION_TIMEOUT',
        'LEAVE_BALANCE_OPENING_SET',
        'LEAVE_BALANCE_CORRECTED',
        'LEAVE_ROLLOVER_APPLIED',
        'LEAVE_BALANCE_DEDUCTED',
        'LEAVE_PROOF_GENERATED',
        'CONFIG_UPDATE',
        'LEAVE_BALANCE_RESERVED',
        'LEAVE_BALANCE_RESERVATION_ADJUSTED',
        'LEAVE_BALANCE_RESERVATION_CONVERTED',
        'LEAVE_BALANCE_RESERVATION_RELEASED',
        'DUTY_POSTPONEMENT',
    ];

    public function up(): void
    {
        $this->applyAuditConstraint($this->auditEventsWithDutyPostponement);

        $channelIds = $this->requiredChannelIds();
        $now = now();

        foreach (['in_app', 'email'] as $channelCode) {
            DB::table('notification_event_channels')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'event_key' => 'cuti.ditangguhkan_tugas_dinas',
                'notification_channel_id' => $channelIds[$channelCode],
                'is_enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->stopRollbackWhenDutyPostponementEvidenceExists();

        $channelIds = $this->requiredChannelIds();

        DB::table('notification_event_channels')
            ->where('event_key', 'cuti.ditangguhkan_tugas_dinas')
            ->whereIn('notification_channel_id', $channelIds->values()->all())
            ->delete();

        $this->applyAuditConstraint($this->auditEventsBeforeDutyPostponement);
    }

    /**
     * Rollback tidak boleh menghilangkan dukungan untuk bukti penangguhan yang sudah tercatat.
     */
    private function stopRollbackWhenDutyPostponementEvidenceExists(): void
    {
        if (DB::table('audit_logs')->where('event', 'DUTY_POSTPONEMENT')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena audit_logs berisi event DUTY_POSTPONEMENT. Arsipkan audit log sebelum menurunkan constraint.');
        }

        if (DB::table('notifications')->where('type', 'cuti.ditangguhkan_tugas_dinas')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena notifikasi penangguhan tugas dinas sudah tercatat. Arsipkan notifikasi sebelum menurunkan migrasi.');
        }

        if (DB::table('leave_balance_ledger')->where('event_type', 'duty_postponement_recorded')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena ledger penangguhan tugas dinas sudah tercatat. Arsipkan ledger sebelum menurunkan migrasi.');
        }
    }

    /** @return Collection<string, string> */
    private function requiredChannelIds(): Collection
    {
        $channelIds = DB::table('ref_notification_channels')
            ->whereIn('code', ['in_app', 'email'])
            ->pluck('id', 'code');

        foreach (['in_app', 'email'] as $requiredChannel) {
            if (! $channelIds->has($requiredChannel)) {
                throw new RuntimeException("Migrasi penangguhan tugas dinas gagal: channel {$requiredChannel} tidak ditemukan.");
            }
        }

        return $channelIds;
    }

    /** @param list<string> $events */
    private function applyAuditConstraint(array $events): void
    {
        $quotedEvents = collect($events)
            ->map(fn (string $event): string => "'{$event}'")
            ->implode(', ');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint($quotedEvents);
        }
    }

    /**
     * SQLite tidak dapat mengganti CHECK inline; seluruh kolom dan index audit dibangun ulang tanpa mengubah data.
     */
    private function rebuildSqliteAuditLogsConstraint(string $quotedEvents): void
    {
        DB::statement('DROP TABLE IF EXISTS audit_logs_new');
        DB::statement("CREATE TABLE audit_logs_new (
            id varchar not null primary key,
            user_id varchar null,
            user_name varchar null,
            event varchar not null check (event in ({$quotedEvents})),
            auditable_type varchar not null,
            auditable_id varchar null,
            old_values text null,
            new_values text null,
            ip_address varchar null,
            user_agent text null,
            created_at datetime default CURRENT_TIMESTAMP not null
        )");
        DB::statement('INSERT INTO audit_logs_new (id, user_id, user_name, event, auditable_type, auditable_id, old_values, new_values, ip_address, user_agent, created_at) SELECT id, user_id, user_name, event, auditable_type, auditable_id, old_values, new_values, ip_address, user_agent, created_at FROM audit_logs');
        DB::statement('DROP TABLE audit_logs');
        DB::statement('ALTER TABLE audit_logs_new RENAME TO audit_logs');
        DB::statement('CREATE INDEX audit_logs_user_id_index ON audit_logs (user_id)');
        DB::statement('CREATE INDEX audit_logs_event_index ON audit_logs (event)');
        DB::statement('CREATE INDEX audit_logs_auditable_type_index ON audit_logs (auditable_type)');
        DB::statement('CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at)');
    }
};
