<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ('CREATE', 'UPDATE', 'DELETE', 'SOFT_DELETE', 'RESTORE', 'LOGIN', 'LOGOUT', 'APPROVE', 'POSTPONE', 'IMPORT', 'SESSION_TIMEOUT'))");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint(includeSessionTimeout: true);
        }
    }

    public function down(): void
    {
        $this->stopRollbackWhenSessionTimeoutAuditExists();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ('CREATE', 'UPDATE', 'DELETE', 'SOFT_DELETE', 'RESTORE', 'LOGIN', 'LOGOUT', 'APPROVE', 'POSTPONE', 'IMPORT'))");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint(includeSessionTimeout: false);
        }
    }

    /**
     * Audit log bersifat append-only; rollback harus gagal jelas daripada menghapus jejak timeout diam-diam.
     */
    private function stopRollbackWhenSessionTimeoutAuditExists(): void
    {
        if ((int) DB::table('audit_logs')->where('event', 'SESSION_TIMEOUT')->count() > 0) {
            throw new RuntimeException('Rollback dibatalkan karena audit_logs berisi event SESSION_TIMEOUT. Arsipkan audit log sebelum menurunkan constraint.');
        }
    }

    /**
     * SQLite tidak bisa drop CHECK constraint inline, jadi tabel audit dibangun ulang dengan schema yang sama.
     */
    private function rebuildSqliteAuditLogsConstraint(bool $includeSessionTimeout): void
    {
        $events = [
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
        ];

        if ($includeSessionTimeout) {
            $events[] = 'SESSION_TIMEOUT';
        }

        $quotedEvents = collect($events)
            ->map(fn (string $event): string => "'{$event}'")
            ->implode(', ');

        DB::statement('DROP TABLE IF EXISTS audit_logs_new');
        DB::statement("CREATE TABLE audit_logs_new (
            id varchar not null primary key,
            user_id varchar null,
            user_name varchar null,
            event varchar not null check (event in ($quotedEvents)),
            auditable_type varchar not null,
            auditable_id varchar null,
            old_values text null,
            new_values text null,
            ip_address varchar null,
            user_agent text null,
            created_at datetime default CURRENT_TIMESTAMP not null
        )");
        DB::statement("INSERT INTO audit_logs_new (id, user_id, user_name, event, auditable_type, auditable_id, old_values, new_values, ip_address, user_agent, created_at) SELECT id, user_id, user_name, event, auditable_type, auditable_id, old_values, new_values, ip_address, user_agent, created_at FROM audit_logs WHERE event != 'SESSION_TIMEOUT'");
        DB::statement('DROP TABLE audit_logs');
        DB::statement('ALTER TABLE audit_logs_new RENAME TO audit_logs');
        DB::statement('CREATE INDEX audit_logs_user_id_index ON audit_logs (user_id)');
        DB::statement('CREATE INDEX audit_logs_event_index ON audit_logs (event)');
        DB::statement('CREATE INDEX audit_logs_auditable_type_index ON audit_logs (auditable_type)');
        DB::statement('CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at)');
    }
};
