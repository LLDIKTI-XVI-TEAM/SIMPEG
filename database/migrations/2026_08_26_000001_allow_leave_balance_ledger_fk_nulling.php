<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_leave_balance_ledger_append_only() RETURNS trigger AS $$
BEGIN
    -- PostgreSQL menjalankan ON DELETE SET NULL sebagai UPDATE internal. Hanya perubahan FK
    -- akibat parent yang benar-benar sudah dihapus yang boleh melewati kontrak append-only.
    IF TG_OP = 'UPDATE'
        AND (
            OLD.created_by IS NOT DISTINCT FROM NEW.created_by
            OR (
                OLD.created_by IS NOT NULL
                AND NEW.created_by IS NULL
                AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.created_by)
            )
        )
        AND (
            OLD.leave_request_id IS NOT DISTINCT FROM NEW.leave_request_id
            OR (
                OLD.leave_request_id IS NOT NULL
                AND NEW.leave_request_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM leave_requests WHERE id = OLD.leave_request_id)
            )
        )
        AND (
            OLD.leave_balance_id IS NOT DISTINCT FROM NEW.leave_balance_id
            OR (
                OLD.leave_balance_id IS NOT NULL
                AND NEW.leave_balance_id IS NULL
                AND NOT EXISTS (SELECT 1 FROM leave_balances WHERE id = OLD.leave_balance_id)
            )
        )
        AND (
            OLD.created_by IS DISTINCT FROM NEW.created_by
            OR OLD.leave_request_id IS DISTINCT FROM NEW.leave_request_id
            OR OLD.leave_balance_id IS DISTINCT FROM NEW.leave_balance_id
        )
        AND OLD.id IS NOT DISTINCT FROM NEW.id
        AND OLD.employee_id IS NOT DISTINCT FROM NEW.employee_id
        AND OLD.tahun IS NOT DISTINCT FROM NEW.tahun
        AND OLD.event_type IS NOT DISTINCT FROM NEW.event_type
        AND OLD.amount IS NOT DISTINCT FROM NEW.amount
        AND OLD.source_year IS NOT DISTINCT FROM NEW.source_year
        AND OLD.sumber_carry_over IS NOT DISTINCT FROM NEW.sumber_carry_over
        AND OLD.reason IS NOT DISTINCT FROM NEW.reason
        AND OLD.dedup_key IS NOT DISTINCT FROM NEW.dedup_key
        AND OLD.metadata::jsonb IS NOT DISTINCT FROM NEW.metadata::jsonb
        AND OLD.occurred_at IS NOT DISTINCT FROM NEW.occurred_at
        AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at
        AND OLD.updated_at IS NOT DISTINCT FROM NEW.updated_at
    THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'ledger saldo cuti bersifat append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_leave_balance_ledger_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'ledger saldo cuti bersifat append-only';
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};
