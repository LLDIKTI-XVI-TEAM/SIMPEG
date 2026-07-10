<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Daftar event audit yang berlaku setelah pemotongan saldo cuti final mendapat jejak audit khusus.
     * Ditambahkan secara additif di atas constraint sebelumnya agar aman pada data yang sudah ada.
     *
     * @var list<string>
     */
    private array $eventsWithDeducted = [
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
    ];

    /**
     * Daftar event audit sebelum penambahan LEAVE_BALANCE_DEDUCTED, dipakai saat rollback.
     *
     * @var list<string>
     */
    private array $eventsWithoutDeducted = [
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
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->applyPgsqlConstraint($this->eventsWithDeducted);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint($this->eventsWithDeducted);
        }
    }

    public function down(): void
    {
        $this->stopRollbackWhenDeductedAuditExists();

        if (DB::getDriverName() === 'pgsql') {
            $this->applyPgsqlConstraint($this->eventsWithoutDeducted);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint($this->eventsWithoutDeducted);
        }
    }

    /**
     * Audit pemotongan saldo adalah bukti administratif; rollback harus berhenti daripada menghapus event dari constraint.
     */
    private function stopRollbackWhenDeductedAuditExists(): void
    {
        if ((int) DB::table('audit_logs')->where('event', 'LEAVE_BALANCE_DEDUCTED')->count() > 0) {
            throw new RuntimeException('Rollback dibatalkan karena audit_logs berisi event LEAVE_BALANCE_DEDUCTED. Arsipkan audit log sebelum menurunkan constraint.');
        }
    }

    /**
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
     * SQLite tidak mendukung ubah CHECK constraint inline, jadi tabel audit dibangun ulang seperti migrasi sebelumnya.
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
