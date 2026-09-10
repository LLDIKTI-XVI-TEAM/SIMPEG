<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Daftar event terminal setelah SSO_BINDING dan SSO_MAPPING_REJECTED ditambahkan.
     * Harus konsisten dengan seluruh migration sebelumnya yang memodifikasi constraint.
     *
     * @var list<string>
     */
    private array $eventsWithSsoIdentity = [
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
        'SWITCH_ROLE',
        'REVERT_ROLE',
        'ROLE_SIMULATION_USAGE',
        // Evidence lifecycle identity mapping SSO (Issue #6): pengikatan pertama
        // subject Keycloak pada user, dan penolakan mapping/konflik identitas.
        'SSO_BINDING',
        'SSO_MAPPING_REJECTED',
    ];

    /**
     * Daftar event sebelum SSO_BINDING/SSO_MAPPING_REJECTED (untuk rollback).
     *
     * @var list<string>
     */
    private array $eventsWithoutSsoIdentity = [
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
        'SWITCH_ROLE',
        'REVERT_ROLE',
        'ROLE_SIMULATION_USAGE',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $quotedEvents = collect($this->eventsWithSsoIdentity)
                ->map(fn (string $event): string => "'{$event}'")->join(', ');

            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $quotedEvents = collect($this->eventsWithSsoIdentity)
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

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $quotedEvents = collect($this->eventsWithSsoIdentity)
                ->map(fn (string $event): string => "'{$event}'")->join(', ');

            DB::statement("ALTER TABLE audit_logs MODIFY COLUMN event ENUM({$quotedEvents}) NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::table('audit_logs')->whereIn('event', ['SSO_BINDING', 'SSO_MAPPING_REJECTED'])->exists()) {
            throw new RuntimeException(
                'Rollback dibatalkan karena audit_logs berisi event SSO_BINDING/SSO_MAPPING_REJECTED. '.
                'Hapus baris tersebut terlebih dahulu sebelum menurunkan constraint.'
            );
        }

        if (DB::getDriverName() === 'pgsql') {
            $quotedEvents = collect($this->eventsWithoutSsoIdentity)
                ->map(fn (string $event): string => "'{$event}'")->join(', ');

            DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
            DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            $quotedEvents = collect($this->eventsWithoutSsoIdentity)
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

            return;
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $quotedEvents = collect($this->eventsWithoutSsoIdentity)
                ->map(fn (string $event): string => "'{$event}'")->join(', ');

            DB::statement("ALTER TABLE audit_logs MODIFY COLUMN event ENUM({$quotedEvents}) NOT NULL");
        }
    }
};
