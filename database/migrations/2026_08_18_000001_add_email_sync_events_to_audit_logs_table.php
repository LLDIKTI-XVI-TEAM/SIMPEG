<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Event EMAIL_SYNCED mencatat sinkronisasi email canonical (email_pribadi) pegawai dari
     * claim Keycloak terverifikasi saat login; EMAIL_CONFLICT mencatat benturan index unik
     * yang membuat sinkronisasi tidak dapat diselesaikan (email pegawai tidak berubah, login
     * tetap berhasil). Constraint diperluas secara additif agar seluruh event yang sudah ada
     * tetap valid.
     *
     * @var list<string>
     */
    private array $eventsWithEmailSync = [
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
        'VERIFY',
        'DECIDE',
        'CHANGE_REQUESTED',
        'DEFER',
        'NOT_APPROVED',
        'EMAIL_SYNCED',
        'EMAIL_CONFLICT',
    ];

    /**
     * Daftar event sebelum EMAIL_SYNCED/EMAIL_CONFLICT ditambahkan.
     *
     * @var list<string>
     */
    private array $eventsWithoutEmailSync = [
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
        'VERIFY',
        'DECIDE',
        'CHANGE_REQUESTED',
        'DEFER',
        'NOT_APPROVED',
    ];

    /**
     * Event yang diperkenalkan migrasi ini.
     *
     * @var list<string>
     */
    private array $newEmailSyncEvents = [
        'EMAIL_SYNCED',
        'EMAIL_CONFLICT',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->applyPgsqlConstraint($this->eventsWithEmailSync);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint($this->eventsWithEmailSync);
        }
    }

    public function down(): void
    {
        $this->stopRollbackWhenEmailSyncAuditExists();

        if (DB::getDriverName() === 'pgsql') {
            $this->applyPgsqlConstraint($this->eventsWithoutEmailSync);

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqliteAuditLogsConstraint($this->eventsWithoutEmailSync);
        }
    }

    /**
     * Audit log bersifat append-only; rollback harus gagal terang daripada menghapus jejak
     * sinkronisasi email atau membiarkan baris yang melanggar constraint.
     */
    private function stopRollbackWhenEmailSyncAuditExists(): void
    {
        if (DB::table('audit_logs')->whereIn('event', $this->newEmailSyncEvents)->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena audit_logs berisi event EMAIL_SYNCED/EMAIL_CONFLICT. Arsipkan audit log sebelum menurunkan constraint.');
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
     * SQLite tidak mendukung perubahan CHECK inline, sehingga tabel dibangun ulang sambil menyalin
     * setiap kolom, data, dan index audit wajib termasuk index nama operator.
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
        DB::statement('CREATE INDEX audit_logs_user_name_index ON audit_logs (user_name)');
    }
};