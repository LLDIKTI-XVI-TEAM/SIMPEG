<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Event CONFIG_UPDATE mencatat perubahan konfigurasi non-data seperti
     * menonaktifkan/mengaktifkan item reference table (kebijakan hapus hybrid)
     * dan toggle kebijakan channel notifikasi, sehingga jejaknya terpisah dari
     * UPDATE data biasa. Constraint diperluas secara additif agar seluruh event
     * yang sudah ada tetap valid.
     *
     * @var list<string>
     */
    private array $eventsWithConfigUpdate = [
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
    ];

    /**
     * Daftar event sebelum CONFIG_UPDATE ditambahkan.
     *
     * @var list<string>
     */
    private array $eventsWithoutConfigUpdate = [
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
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->applyPgsqlConstraint($this->eventsWithConfigUpdate);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint($this->eventsWithConfigUpdate);
        }
    }

    public function down(): void
    {
        $this->stopRollbackWhenConfigUpdateAuditExists();

        if (DB::getDriverName() === 'pgsql') {
            $this->applyPgsqlConstraint($this->eventsWithoutConfigUpdate);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint($this->eventsWithoutConfigUpdate);
        }
    }

    /**
     * Audit log bersifat append-only; rollback harus gagal jelas daripada
     * menghapus jejak perubahan konfigurasi diam-diam.
     */
    private function stopRollbackWhenConfigUpdateAuditExists(): void
    {
        if ((int) DB::table('audit_logs')->where('event', 'CONFIG_UPDATE')->count() > 0) {
            throw new RuntimeException('Rollback dibatalkan karena audit_logs berisi event CONFIG_UPDATE. Arsipkan audit log sebelum menurunkan constraint.');
        }
    }

    /**
     * PostgreSQL dapat mengganti CHECK constraint secara langsung tanpa membangun ulang tabel audit.
     *
     * @param  list<string>  $events
     */
    private function applyPgsqlConstraint(array $events): void
    {
        $quotedEvents = collect($events)
            ->map(fn (string $event): string => "'{$event}'")
            ->implode(', ');

        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");
    }

    /**
     * SQLite tidak mendukung perubahan CHECK inline, sehingga tabel dibangun ulang sambil menyalin setiap kolom, data, dan index audit wajib.
     *
     * @param  list<string>  $events
     */
    private function rebuildSqliteAuditLogsConstraint(array $events): void
    {
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
        DB::statement('INSERT INTO audit_logs_new (id, user_id, user_name, event, auditable_type, auditable_id, old_values, new_values, ip_address, user_agent, created_at) SELECT id, user_id, user_name, event, auditable_type, auditable_id, old_values, new_values, ip_address, user_agent, created_at FROM audit_logs');
        DB::statement('DROP TABLE audit_logs');
        DB::statement('ALTER TABLE audit_logs_new RENAME TO audit_logs');
        DB::statement('CREATE INDEX audit_logs_user_id_index ON audit_logs (user_id)');
        DB::statement('CREATE INDEX audit_logs_event_index ON audit_logs (event)');
        DB::statement('CREATE INDEX audit_logs_auditable_type_index ON audit_logs (auditable_type)');
        DB::statement('CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at)');
    }
};
