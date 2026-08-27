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
CREATE OR REPLACE FUNCTION guard_leave_usage_reconciliation_update() RETURNS trigger AS $$
BEGIN
    -- ON DELETE SET NULL boleh melepas aktor yang sudah hilang tanpa membuka mutasi historis lain.
    IF TG_OP = 'UPDATE'
        AND OLD.recorded_by IS NOT NULL
        AND NEW.recorded_by IS NULL
        AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.recorded_by)
        AND OLD.id IS NOT DISTINCT FROM NEW.id
        AND OLD.employee_id IS NOT DISTINCT FROM NEW.employee_id
        AND OLD.balance_year IS NOT DISTINCT FROM NEW.balance_year
        AND OLD.reconciled_at IS NOT DISTINCT FROM NEW.reconciled_at
        AND OLD.status IS NOT DISTINCT FROM NEW.status
        AND OLD.replaces_id IS NOT DISTINCT FROM NEW.replaces_id
        AND OLD.administrative_note IS NOT DISTINCT FROM NEW.administrative_note
        AND OLD.correction_reason IS NOT DISTINCT FROM NEW.correction_reason
        AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at
        AND OLD.updated_at IS NOT DISTINCT FROM NEW.updated_at
    THEN
        RETURN NEW;
    END IF;

    IF OLD.id IS DISTINCT FROM NEW.id
        OR OLD.employee_id IS DISTINCT FROM NEW.employee_id
        OR OLD.balance_year IS DISTINCT FROM NEW.balance_year
        OR OLD.reconciled_at IS DISTINCT FROM NEW.reconciled_at
        OR OLD.replaces_id IS DISTINCT FROM NEW.replaces_id
        OR OLD.administrative_note IS DISTINCT FROM NEW.administrative_note
        OR OLD.correction_reason IS DISTINCT FROM NEW.correction_reason
        OR OLD.recorded_by IS DISTINCT FROM NEW.recorded_by
        OR OLD.created_at IS DISTINCT FROM NEW.created_at
    THEN
        RAISE EXCEPTION 'kolom substantif bersifat immutable pada set pencatatan pemakaian cuti';
    END IF;

    IF OLD.status <> 'active' THEN
        RAISE EXCEPTION 'lifecycle terminal bersifat immutable pada set pencatatan pemakaian cuti';
    END IF;

    IF NEW.status <> 'superseded' THEN
        RAISE EXCEPTION 'set aktif hanya dapat ditutup sebagai superseded';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION guard_leave_usage_record_update() RETURNS trigger AS $$
BEGIN
    -- ON DELETE SET NULL boleh melepas aktor yang sudah hilang tanpa membuka mutasi historis lain.
    IF TG_OP = 'UPDATE'
        AND OLD.recorded_by IS NOT NULL
        AND NEW.recorded_by IS NULL
        AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.recorded_by)
        AND OLD.id IS NOT DISTINCT FROM NEW.id
        AND OLD.employee_id IS NOT DISTINCT FROM NEW.employee_id
        AND OLD.leave_type_id IS NOT DISTINCT FROM NEW.leave_type_id
        AND OLD.source_type IS NOT DISTINCT FROM NEW.source_type
        AND OLD.reconciliation_set_id IS NOT DISTINCT FROM NEW.reconciliation_set_id
        AND OLD.leave_request_id IS NOT DISTINCT FROM NEW.leave_request_id
        AND OLD.leave_request_case_id IS NOT DISTINCT FROM NEW.leave_request_case_id
        AND OLD.usage_year IS NOT DISTINCT FROM NEW.usage_year
        AND OLD.effective_date IS NOT DISTINCT FROM NEW.effective_date
        AND OLD.start_date IS NOT DISTINCT FROM NEW.start_date
        AND OLD.end_date IS NOT DISTINCT FROM NEW.end_date
        AND OLD.workdays IS NOT DISTINCT FROM NEW.workdays
        AND OLD.administrative_note IS NOT DISTINCT FROM NEW.administrative_note
        AND OLD.approval_document_number IS NOT DISTINCT FROM NEW.approval_document_number
        AND OLD.record_status IS NOT DISTINCT FROM NEW.record_status
        AND OLD.replaces_id IS NOT DISTINCT FROM NEW.replaces_id
        AND OLD.correction_reason IS NOT DISTINCT FROM NEW.correction_reason
        AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at
        AND OLD.updated_at IS NOT DISTINCT FROM NEW.updated_at
    THEN
        RETURN NEW;
    END IF;

    IF OLD.id IS DISTINCT FROM NEW.id
        OR OLD.employee_id IS DISTINCT FROM NEW.employee_id
        OR OLD.leave_type_id IS DISTINCT FROM NEW.leave_type_id
        OR OLD.source_type IS DISTINCT FROM NEW.source_type
        OR OLD.reconciliation_set_id IS DISTINCT FROM NEW.reconciliation_set_id
        OR OLD.leave_request_id IS DISTINCT FROM NEW.leave_request_id
        OR OLD.leave_request_case_id IS DISTINCT FROM NEW.leave_request_case_id
        OR OLD.usage_year IS DISTINCT FROM NEW.usage_year
        OR OLD.effective_date IS DISTINCT FROM NEW.effective_date
        OR OLD.start_date IS DISTINCT FROM NEW.start_date
        OR OLD.end_date IS DISTINCT FROM NEW.end_date
        OR OLD.workdays IS DISTINCT FROM NEW.workdays
        OR OLD.administrative_note IS DISTINCT FROM NEW.administrative_note
        OR OLD.replaces_id IS DISTINCT FROM NEW.replaces_id
        OR OLD.recorded_by IS DISTINCT FROM NEW.recorded_by
        OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
        RAISE EXCEPTION 'kolom substantif bersifat immutable pada fakta pemakaian cuti';
    END IF;

    IF OLD.record_status <> 'active' THEN
        RAISE EXCEPTION 'lifecycle terminal bersifat immutable pada fakta pemakaian cuti';
    END IF;

    -- Fakta dari workflow approval adalah hasil final; pembatalan dilakukan pada sumber pengajuannya.
    IF OLD.source_type = 'approved_request' THEN
        RAISE EXCEPTION 'lifecycle fakta approved_request bersifat immutable';
    END IF;

    IF OLD.source_type = 'annual_reconciliation' AND NEW.record_status <> 'superseded' THEN
        RAISE EXCEPTION 'fakta annual_reconciliation hanya dapat ditutup melalui set pengganti';
    END IF;

    IF NEW.record_status NOT IN ('superseded', 'cancelled')
        OR NULLIF(BTRIM(NEW.correction_reason), '') IS NULL THEN
        RAISE EXCEPTION 'fakta aktif hanya dapat ditutup sebagai superseded atau cancelled dengan alasan';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION remember_leave_usage_lifecycle_effect() RETURNS trigger AS $$
DECLARE
    effects jsonb;
    effect_key text;
BEGIN
    effects := COALESCE(
        NULLIF(current_setting('simpeg.leave_usage_lifecycle_effects', true), ''),
        '{}'
    )::jsonb;

    IF TG_TABLE_NAME = 'leave_balance_ledger' THEN
        effect_key := 'ledger:' || NEW.event_type || ':' || (NEW.metadata->>'usage_record_id');
    ELSE
        effect_key := 'audit:' || NEW.auditable_type || ':' || NEW.event || ':' || NEW.auditable_id::text;
    END IF;

    PERFORM set_config(
        'simpeg.leave_usage_lifecycle_effects',
        (effects || jsonb_build_object(effect_key, true))::text,
        true
    );

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS leave_usage_ledger_lifecycle_effect ON leave_balance_ledger;
CREATE TRIGGER leave_usage_ledger_lifecycle_effect
AFTER INSERT ON leave_balance_ledger
FOR EACH ROW
WHEN (NEW.event_type IN ('usage_fact_recorded', 'usage_fact_superseded', 'usage_fact_cancelled'))
EXECUTE FUNCTION remember_leave_usage_lifecycle_effect();

DROP TRIGGER IF EXISTS leave_usage_audit_lifecycle_effect ON audit_logs;
CREATE TRIGGER leave_usage_audit_lifecycle_effect
AFTER INSERT ON audit_logs
FOR EACH ROW
WHEN (
    NEW.event = 'UPDATE'
    AND NEW.auditable_type IN ('LeaveUsageRecord', 'LeaveUsageReconciliationSet')
)
EXECUTE FUNCTION remember_leave_usage_lifecycle_effect();

CREATE OR REPLACE FUNCTION enforce_leave_usage_record_lifecycle_contract() RETURNS trigger AS $$
DECLARE
    effects jsonb;
    replacement_id uuid;
    replacement_set_id uuid;
    replacement_balance_year integer;
    terminal_event text;
BEGIN
    IF OLD.source_type = 'annual_reconciliation' THEN
        SELECT replacement.id, replacement_set.id, replacement_set.balance_year
        INTO replacement_id, replacement_set_id, replacement_balance_year
        FROM leave_usage_reconciliation_sets AS replacement_set
        JOIN leave_usage_reconciliation_sets AS previous_set
          ON previous_set.id = OLD.reconciliation_set_id
        LEFT JOIN leave_usage_records AS replacement
          ON replacement.reconciliation_set_id = replacement_set.id
         AND replacement.replaces_id = OLD.id
         AND replacement.source_type = 'annual_reconciliation'
         AND replacement.record_status = 'active'
         AND replacement.employee_id = OLD.employee_id
         AND replacement.leave_type_id = OLD.leave_type_id
         AND replacement.usage_year = OLD.usage_year
        WHERE replacement_set.replaces_id = OLD.reconciliation_set_id
          AND replacement_set.employee_id = OLD.employee_id
          AND replacement_set.balance_year >= previous_set.balance_year
          AND replacement_set.status = 'active';

        IF replacement_set_id IS NULL THEN
            RAISE EXCEPTION 'fakta annual_reconciliation wajib memiliki lineage pengganti set aktif';
        END IF;

        -- Rollover menggeser window satu tahun: fakta yang keluar dari N-2 tidak memiliki row pengganti.
        IF OLD.usage_year BETWEEN replacement_balance_year - 2 AND replacement_balance_year
            AND replacement_id IS NULL THEN
            RAISE EXCEPTION 'fakta annual_reconciliation wajib memiliki lineage pengganti aktif';
        END IF;
    ELSIF OLD.source_type = 'manual_external' AND NEW.record_status = 'superseded' THEN
        SELECT replacement.id INTO replacement_id
        FROM leave_usage_records AS replacement
        WHERE replacement.replaces_id = OLD.id
          AND replacement.source_type = 'manual_external'
          AND replacement.record_status = 'active'
          AND replacement.employee_id = OLD.employee_id;

        IF replacement_id IS NULL THEN
            RAISE EXCEPTION 'fakta manual_external superseded wajib memiliki lineage pengganti aktif';
        END IF;
    END IF;

    terminal_event := CASE NEW.record_status
        WHEN 'superseded' THEN 'usage_fact_superseded'
        WHEN 'cancelled' THEN 'usage_fact_cancelled'
    END;
    effects := COALESCE(
        NULLIF(current_setting('simpeg.leave_usage_lifecycle_effects', true), ''),
        '{}'
    )::jsonb;

    IF NOT EXISTS (
        SELECT 1
        FROM leave_balance_ledger AS effect
        WHERE effect.event_type = terminal_event
          AND effect.metadata->>'usage_record_id' = OLD.id::text
    ) OR NOT EXISTS (
        SELECT 1
        FROM audit_logs AS effect
        WHERE effect.auditable_type = 'LeaveUsageRecord'
          AND effect.event = 'UPDATE'
          AND effect.auditable_id IN (OLD.id, COALESCE(replacement_id, OLD.id))
    ) OR NOT effects ? ('ledger:' || terminal_event || ':' || OLD.id::text)
      OR NOT (
          effects ? ('audit:LeaveUsageRecord:UPDATE:' || OLD.id::text)
          OR effects ? ('audit:LeaveUsageRecord:UPDATE:' || COALESCE(replacement_id, OLD.id)::text)
      )
    THEN
        RAISE EXCEPTION 'perubahan lifecycle fakta wajib memiliki efek resmi ledger dan audit dalam transaksi yang sama';
    END IF;

    IF replacement_id IS NOT NULL AND (
        NOT EXISTS (
            SELECT 1
            FROM leave_balance_ledger AS effect
            WHERE effect.event_type = 'usage_fact_recorded'
              AND effect.metadata->>'usage_record_id' = replacement_id::text
        ) OR NOT effects ? ('ledger:usage_fact_recorded:' || replacement_id::text)
    ) THEN
        RAISE EXCEPTION 'lineage pengganti wajib memiliki efek resmi pencatatan dalam transaksi yang sama';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION enforce_leave_usage_reconciliation_lifecycle_contract() RETURNS trigger AS $$
DECLARE
    effects jsonb;
    replacement_set_id uuid;
    replacement_balance_year integer;
    matched_records integer;
    previous_records integer;
    terminal_records integer;
    replacement_records integer;
    previous_distinct_years integer;
    replacement_distinct_years integer;
    previous_min_year integer;
    previous_max_year integer;
    replacement_min_year integer;
    replacement_max_year integer;
    expected_overlap integer;
BEGIN
    SELECT replacement.id, replacement.balance_year
    INTO replacement_set_id, replacement_balance_year
    FROM leave_usage_reconciliation_sets AS replacement
    WHERE replacement.replaces_id = OLD.id
      AND replacement.employee_id = OLD.employee_id
      AND replacement.balance_year >= OLD.balance_year
      AND replacement.status = 'active';

    IF replacement_set_id IS NULL THEN
        RAISE EXCEPTION 'set rekonsiliasi wajib memiliki lineage pengganti aktif';
    END IF;

    SELECT
        COUNT(*),
        COUNT(*) FILTER (WHERE record_status = 'superseded'),
        COUNT(DISTINCT usage_year),
        MIN(usage_year),
        MAX(usage_year)
    INTO
        previous_records,
        terminal_records,
        previous_distinct_years,
        previous_min_year,
        previous_max_year
    FROM leave_usage_records
    WHERE reconciliation_set_id = OLD.id
      AND source_type = 'annual_reconciliation';

    SELECT COUNT(*), COUNT(DISTINCT usage_year), MIN(usage_year), MAX(usage_year)
    INTO replacement_records, replacement_distinct_years, replacement_min_year, replacement_max_year
    FROM leave_usage_records
    WHERE reconciliation_set_id = replacement_set_id
      AND source_type = 'annual_reconciliation'
      AND record_status = 'active';

    SELECT COUNT(*) INTO matched_records
    FROM leave_usage_records AS previous
    JOIN leave_usage_records AS replacement
      ON replacement.replaces_id = previous.id
    WHERE previous.reconciliation_set_id = OLD.id
      AND previous.source_type = 'annual_reconciliation'
      AND previous.record_status = 'superseded'
      AND replacement.reconciliation_set_id = replacement_set_id
      AND replacement.source_type = 'annual_reconciliation'
      AND replacement.record_status = 'active'
      AND replacement.employee_id = previous.employee_id
      AND replacement.leave_type_id = previous.leave_type_id
      AND replacement.usage_year = previous.usage_year;

    expected_overlap := GREATEST(0, 3 - (replacement_balance_year - OLD.balance_year));

    IF previous_records <> 3
        OR terminal_records <> 3
        OR previous_distinct_years <> 3
        OR previous_min_year <> OLD.balance_year - 2
        OR previous_max_year <> OLD.balance_year
        OR replacement_records <> 3
        OR replacement_distinct_years <> 3
        OR replacement_min_year <> replacement_balance_year - 2
        OR replacement_max_year <> replacement_balance_year
        OR matched_records <> expected_overlap
    THEN
        RAISE EXCEPTION 'lineage pengganti set wajib mencakup fakta annual_reconciliation sesuai overlap window tiga tahun';
    END IF;

    effects := COALESCE(
        NULLIF(current_setting('simpeg.leave_usage_lifecycle_effects', true), ''),
        '{}'
    )::jsonb;

    IF NOT EXISTS (
        SELECT 1
        FROM audit_logs AS effect
        WHERE effect.auditable_type = 'LeaveUsageReconciliationSet'
          AND effect.auditable_id = replacement_set_id
          AND effect.event = 'UPDATE'
    ) OR NOT effects ? ('audit:LeaveUsageReconciliationSet:UPDATE:' || replacement_set_id::text)
    THEN
        RAISE EXCEPTION 'lineage pengganti set wajib memiliki efek resmi audit dalam transaksi yang sama';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS leave_usage_record_lifecycle_contract ON leave_usage_records;
CREATE CONSTRAINT TRIGGER leave_usage_record_lifecycle_contract
AFTER UPDATE OF record_status ON leave_usage_records
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
WHEN (OLD.record_status IS DISTINCT FROM NEW.record_status)
EXECUTE FUNCTION enforce_leave_usage_record_lifecycle_contract();

DROP TRIGGER IF EXISTS leave_usage_reconciliation_lifecycle_contract ON leave_usage_reconciliation_sets;
CREATE CONSTRAINT TRIGGER leave_usage_reconciliation_lifecycle_contract
AFTER UPDATE OF status ON leave_usage_reconciliation_sets
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
WHEN (OLD.status IS DISTINCT FROM NEW.status)
EXECUTE FUNCTION enforce_leave_usage_reconciliation_lifecycle_contract();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_usage_record_lifecycle_contract ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_reconciliation_lifecycle_contract ON leave_usage_reconciliation_sets;
DROP TRIGGER IF EXISTS leave_usage_ledger_lifecycle_effect ON leave_balance_ledger;
DROP TRIGGER IF EXISTS leave_usage_audit_lifecycle_effect ON audit_logs;
DROP FUNCTION IF EXISTS enforce_leave_usage_record_lifecycle_contract();
DROP FUNCTION IF EXISTS enforce_leave_usage_reconciliation_lifecycle_contract();
DROP FUNCTION IF EXISTS remember_leave_usage_lifecycle_effect();

CREATE OR REPLACE FUNCTION guard_leave_usage_reconciliation_update() RETURNS trigger AS $$
BEGIN
    -- PostgreSQL mengeksekusi ON DELETE SET NULL sebagai UPDATE internal. Referensi aktor
    -- boleh dilepas hanya setelah user benar-benar hilang, tanpa mengubah fakta historis lain.
    IF TG_OP = 'UPDATE'
        AND OLD.recorded_by IS NOT NULL
        AND NEW.recorded_by IS NULL
        AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.recorded_by)
        AND OLD.id IS NOT DISTINCT FROM NEW.id
        AND OLD.employee_id IS NOT DISTINCT FROM NEW.employee_id
        AND OLD.balance_year IS NOT DISTINCT FROM NEW.balance_year
        AND OLD.reconciled_at IS NOT DISTINCT FROM NEW.reconciled_at
        AND OLD.status IS NOT DISTINCT FROM NEW.status
        AND OLD.replaces_id IS NOT DISTINCT FROM NEW.replaces_id
        AND OLD.administrative_note IS NOT DISTINCT FROM NEW.administrative_note
        AND OLD.correction_reason IS NOT DISTINCT FROM NEW.correction_reason
        AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at
        AND OLD.updated_at IS NOT DISTINCT FROM NEW.updated_at
    THEN
        RETURN NEW;
    END IF;

    -- Koreksi dibuat sebagai set pengganti; data set historis tidak boleh ditulis ulang.
    IF OLD.id IS DISTINCT FROM NEW.id
        OR OLD.employee_id IS DISTINCT FROM NEW.employee_id
        OR OLD.balance_year IS DISTINCT FROM NEW.balance_year
        OR OLD.reconciled_at IS DISTINCT FROM NEW.reconciled_at
        OR OLD.replaces_id IS DISTINCT FROM NEW.replaces_id
        OR OLD.administrative_note IS DISTINCT FROM NEW.administrative_note
        OR OLD.correction_reason IS DISTINCT FROM NEW.correction_reason
        OR OLD.recorded_by IS DISTINCT FROM NEW.recorded_by
        OR OLD.created_at IS DISTINCT FROM NEW.created_at
    THEN
        RAISE EXCEPTION 'kolom substantif bersifat immutable pada set pencatatan pemakaian cuti';
    END IF;

    -- Set aktif hanya boleh ditutup sekali ketika set pengganti berhasil dibuat.
    IF OLD.status <> 'active' THEN
        RAISE EXCEPTION 'lifecycle terminal bersifat immutable pada set pencatatan pemakaian cuti';
    END IF;

    IF NEW.status <> 'superseded' THEN
        RAISE EXCEPTION 'set aktif hanya dapat ditutup sebagai superseded';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION guard_leave_usage_record_update() RETURNS trigger AS $$
BEGIN
    -- PostgreSQL mengeksekusi ON DELETE SET NULL sebagai UPDATE internal. Referensi aktor
    -- boleh dilepas hanya setelah user benar-benar hilang, tanpa mengubah fakta historis lain.
    IF TG_OP = 'UPDATE'
        AND OLD.recorded_by IS NOT NULL
        AND NEW.recorded_by IS NULL
        AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.recorded_by)
        AND OLD.id IS NOT DISTINCT FROM NEW.id
        AND OLD.employee_id IS NOT DISTINCT FROM NEW.employee_id
        AND OLD.leave_type_id IS NOT DISTINCT FROM NEW.leave_type_id
        AND OLD.source_type IS NOT DISTINCT FROM NEW.source_type
        AND OLD.reconciliation_set_id IS NOT DISTINCT FROM NEW.reconciliation_set_id
        AND OLD.leave_request_id IS NOT DISTINCT FROM NEW.leave_request_id
        AND OLD.leave_request_case_id IS NOT DISTINCT FROM NEW.leave_request_case_id
        AND OLD.usage_year IS NOT DISTINCT FROM NEW.usage_year
        AND OLD.effective_date IS NOT DISTINCT FROM NEW.effective_date
        AND OLD.start_date IS NOT DISTINCT FROM NEW.start_date
        AND OLD.end_date IS NOT DISTINCT FROM NEW.end_date
        AND OLD.workdays IS NOT DISTINCT FROM NEW.workdays
        AND OLD.administrative_note IS NOT DISTINCT FROM NEW.administrative_note
        AND OLD.approval_document_number IS NOT DISTINCT FROM NEW.approval_document_number
        AND OLD.record_status IS NOT DISTINCT FROM NEW.record_status
        AND OLD.replaces_id IS NOT DISTINCT FROM NEW.replaces_id
        AND OLD.correction_reason IS NOT DISTINCT FROM NEW.correction_reason
        AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at
        AND OLD.updated_at IS NOT DISTINCT FROM NEW.updated_at
    THEN
        RETURN NEW;
    END IF;

    -- Isi fakta dikoreksi melalui versi pengganti; UPDATE hanya menutup lifecycle versi aktif.
    IF OLD.id IS DISTINCT FROM NEW.id
        OR OLD.employee_id IS DISTINCT FROM NEW.employee_id
        OR OLD.leave_type_id IS DISTINCT FROM NEW.leave_type_id
        OR OLD.source_type IS DISTINCT FROM NEW.source_type
        OR OLD.reconciliation_set_id IS DISTINCT FROM NEW.reconciliation_set_id
        OR OLD.leave_request_id IS DISTINCT FROM NEW.leave_request_id
        OR OLD.leave_request_case_id IS DISTINCT FROM NEW.leave_request_case_id
        OR OLD.usage_year IS DISTINCT FROM NEW.usage_year
        OR OLD.effective_date IS DISTINCT FROM NEW.effective_date
        OR OLD.start_date IS DISTINCT FROM NEW.start_date
        OR OLD.end_date IS DISTINCT FROM NEW.end_date
        OR OLD.workdays IS DISTINCT FROM NEW.workdays
        OR OLD.administrative_note IS DISTINCT FROM NEW.administrative_note
        OR OLD.replaces_id IS DISTINCT FROM NEW.replaces_id
        OR OLD.recorded_by IS DISTINCT FROM NEW.recorded_by
        OR OLD.created_at IS DISTINCT FROM NEW.created_at THEN
        RAISE EXCEPTION 'kolom substantif bersifat immutable pada fakta pemakaian cuti';
    END IF;

    IF OLD.record_status <> 'active' THEN
        RAISE EXCEPTION 'lifecycle terminal bersifat immutable pada fakta pemakaian cuti';
    END IF;

    IF NEW.record_status NOT IN ('superseded', 'cancelled')
        OR NULLIF(BTRIM(NEW.correction_reason), '') IS NULL THEN
        RAISE EXCEPTION 'fakta aktif hanya dapat ditutup sebagai superseded atau cancelled dengan alasan';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};
