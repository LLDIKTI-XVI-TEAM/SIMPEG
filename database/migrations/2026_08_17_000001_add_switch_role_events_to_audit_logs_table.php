<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Daftar event terbaru setelah SWITCH_ROLE dan REVERT_ROLE ditambahkan.
     * Daftar ini harus konsisten dengan seluruh migration sebelumnya yang memodifikasi constraint.
     *
     * @var list<string>
     */
    private array $eventsWithSwitchRole = [
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
        // Event simulasi role: switch (masuk mode) dan revert (keluar mode) wajib tercatat audit.
        'SWITCH_ROLE',
        'REVERT_ROLE',
    ];

    /**
     * Daftar event sebelum SWITCH_ROLE ditambahkan (untuk rollback).
     *
     * @var list<string>
     */
    private array $eventsWithoutSwitchRole = [
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

    public function up(): void
    {
        // PostgreSQL: ganti CHECK constraint secara langsung tanpa rebuild tabel.
        if (DB::getDriverName() === 'pgsql') {
            $quotedEvents = collect($this->eventsWithSwitchRole)
                ->map(fn (string $event): string => "'{$event}'")->join(', ');

            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");

            return;
        }

        // Rebuild tabel hanya didukung pada SQLite. Driver lain (MySQL/MariaDB/SQL Server)
        // tidak menjalankan rebuild gaya ini; dijaga sebagai no-op yang disengaja karena
        // sintaks di bawah tidak portabel dan aplikasi menyasar PostgreSQL + SQLite (test).
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // SQLite: rebuild tabel karena tidak mendukung ALTER CHECK inline.
        $quotedEvents = collect($this->eventsWithSwitchRole)
            ->map(fn (string $event): string => "'{$event}'")->join(', ');

        DB::statement('ALTER TABLE audit_logs RENAME TO audit_logs_old');
        DB::statement("CREATE TABLE audit_logs (
            id varchar primary key not null,
            user_id varchar,
            user_name varchar,
            event varchar not null check (event in ({$quotedEvents})),
            auditable_type varchar not null,
            auditable_id varchar,
            old_values text,
            new_values text,
            ip_address varchar,
            user_agent varchar,
            created_at datetime default CURRENT_TIMESTAMP not null
        )");
        DB::statement('INSERT INTO audit_logs SELECT * FROM audit_logs_old');
        DB::statement('DROP TABLE audit_logs_old');
        DB::statement('CREATE INDEX audit_logs_event_index ON audit_logs (event)');
        DB::statement('CREATE INDEX audit_logs_user_id_index ON audit_logs (user_id)');
        DB::statement('CREATE INDEX audit_logs_auditable_type_index ON audit_logs (auditable_type)');
        DB::statement('CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at)');
        DB::statement('CREATE INDEX audit_logs_user_name_index ON audit_logs (user_name)');
    }

    public function down(): void
    {
        // Cek apakah ada baris SWITCH_ROLE atau REVERT_ROLE sebelum rollback.
        if (DB::table('audit_logs')->whereIn('event', ['SWITCH_ROLE', 'REVERT_ROLE'])->exists()) {
            throw new RuntimeException(
                'Rollback dibatalkan karena audit_logs berisi event SWITCH_ROLE atau REVERT_ROLE. '.
                'Hapus baris tersebut terlebih dahulu sebelum menurunkan constraint.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            $quotedEvents = collect($this->eventsWithoutSwitchRole)
                ->map(fn (string $event): string => "'{$event}'")->join(', ');

            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");

            return;
        }

        // Rebuild hanya untuk SQLite; driver lain no-op (aplikasi menyasar PostgreSQL + SQLite).
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        $quotedEvents = collect($this->eventsWithoutSwitchRole)
            ->map(fn (string $event): string => "'{$event}'")->join(', ');

        DB::statement('ALTER TABLE audit_logs RENAME TO audit_logs_old');
        DB::statement("CREATE TABLE audit_logs (
            id varchar primary key not null,
            user_id varchar,
            user_name varchar,
            event varchar not null check (event in ({$quotedEvents})),
            auditable_type varchar not null,
            auditable_id varchar,
            old_values text,
            new_values text,
            ip_address varchar,
            user_agent varchar,
            created_at datetime default CURRENT_TIMESTAMP not null
        )");
        DB::statement('INSERT INTO audit_logs SELECT * FROM audit_logs_old');
        DB::statement('DROP TABLE audit_logs_old');
        DB::statement('CREATE INDEX audit_logs_event_index ON audit_logs (event)');
        DB::statement('CREATE INDEX audit_logs_user_id_index ON audit_logs (user_id)');
        DB::statement('CREATE INDEX audit_logs_auditable_type_index ON audit_logs (auditable_type)');
        DB::statement('CREATE INDEX audit_logs_created_at_index ON audit_logs (created_at)');
        DB::statement('CREATE INDEX audit_logs_user_name_index ON audit_logs (user_name)');
    }
};
