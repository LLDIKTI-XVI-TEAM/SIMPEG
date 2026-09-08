<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_usage_record_lifecycle_contract ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_reconciliation_lifecycle_contract ON leave_usage_reconciliation_sets;

CREATE OR REPLACE FUNCTION guard_leave_usage_document_update() RETURNS trigger AS $$
BEGIN
    -- FK uploader boleh kehilangan UUID ketika user dihapus, tanpa menulis ulang bukti historis lain.
    IF OLD.uploaded_by IS NOT NULL
        AND NEW.uploaded_by IS NULL
        AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.uploaded_by)
        AND OLD.id IS NOT DISTINCT FROM NEW.id
        AND OLD.leave_usage_record_id IS NOT DISTINCT FROM NEW.leave_usage_record_id
        AND OLD.original_name IS NOT DISTINCT FROM NEW.original_name
        AND OLD.stored_name IS NOT DISTINCT FROM NEW.stored_name
        AND OLD.path IS NOT DISTINCT FROM NEW.path
        AND OLD.disk IS NOT DISTINCT FROM NEW.disk
        AND OLD.mime_type IS NOT DISTINCT FROM NEW.mime_type
        AND OLD.size_bytes IS NOT DISTINCT FROM NEW.size_bytes
        AND OLD.created_at IS NOT DISTINCT FROM NEW.created_at
        AND OLD.updated_at IS NOT DISTINCT FROM NEW.updated_at
    THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'metadata dokumen pemakaian cuti bersifat append-only dan tidak dapat diubah';
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

    IF OLD.source_type = 'approved_request' THEN
        RAISE EXCEPTION 'lifecycle fakta approved_request bersifat immutable';
    END IF;

    IF NEW.record_status NOT IN ('superseded', 'cancelled')
        OR NULLIF(BTRIM(NEW.correction_reason), '') IS NULL THEN
        RAISE EXCEPTION 'fakta aktif hanya dapat ditutup sebagai superseded atau cancelled dengan alasan';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS leave_usage_audit_lifecycle_effect ON audit_logs;
CREATE TRIGGER leave_usage_audit_lifecycle_effect
AFTER INSERT ON audit_logs
FOR EACH ROW
WHEN (
    NEW.event = 'UPDATE'
    AND NEW.auditable_type = 'LeaveUsageRecord'
)
EXECUTE FUNCTION remember_leave_usage_lifecycle_effect();
SQL);

        DB::unprepared(<<<'SQL'
-- Rekonsiliasi tahunan lama merupakan proyeksi agregat. Pindahkan dokumen
-- set ke record ringkasan yang dipertahankan, lalu terminal-kan ringkasannya
-- agar fakta itemized tetap menjadi satu-satunya sumber pemakaian aktif.
DROP TRIGGER IF EXISTS leave_usage_document_restrict_update ON leave_usage_documents;
DROP TRIGGER IF EXISTS leave_usage_record_restrict_update ON leave_usage_records;

UPDATE leave_usage_documents AS document
SET leave_usage_record_id = summary.record_id,
    leave_usage_reconciliation_set_id = NULL
FROM (
    SELECT DISTINCT ON (reconciliation_set_id)
        reconciliation_set_id,
        id AS record_id
    FROM leave_usage_records
    WHERE source_type = 'annual_reconciliation'
    ORDER BY reconciliation_set_id, usage_year, id
) AS summary
WHERE document.leave_usage_reconciliation_set_id = summary.reconciliation_set_id;

DROP TABLE leave_usage_reconciliation_memberships;

ALTER TABLE leave_usage_documents
    DROP CONSTRAINT IF EXISTS leave_usage_documents_leave_usage_reconciliation_set_id_foreign;
DROP INDEX IF EXISTS leave_usage_documents_reconciliation_set_index;
ALTER TABLE leave_usage_documents
    DROP COLUMN leave_usage_reconciliation_set_id;
ALTER TABLE leave_usage_documents
    DROP CONSTRAINT IF EXISTS leave_usage_documents_target_check;
ALTER TABLE leave_usage_documents
    ADD CONSTRAINT leave_usage_documents_target_check
        CHECK (leave_usage_record_id IS NOT NULL);

ALTER TABLE leave_usage_records
    DROP CONSTRAINT IF EXISTS leave_usage_reconciliation_set_id_foreign;
DROP INDEX IF EXISTS leave_usage_reconciliation_year_index;
ALTER TABLE leave_usage_records
    DROP CONSTRAINT IF EXISTS leave_usage_reconciliation_year_unique;

ALTER TABLE leave_usage_records
    DROP CONSTRAINT IF EXISTS leave_usage_source_type_check,
    DROP CONSTRAINT IF EXISTS leave_usage_workdays_check,
    DROP CONSTRAINT IF EXISTS leave_usage_source_contract_check;

UPDATE leave_usage_records
SET source_type = 'manual_external',
    reconciliation_set_id = NULL,
    start_date = effective_date,
    end_date = effective_date,
    workdays = GREATEST(workdays, 1),
    record_status = 'cancelled',
    correction_reason = COALESCE(
        NULLIF(BTRIM(correction_reason), ''),
        'Migrasi ringkasan rekonsiliasi tahunan legacy; fakta itemized dipertahankan sebagai sumber kanonis.'
    ),
    administrative_note = CONCAT(
        administrative_note,
        CASE WHEN administrative_note = '' THEN '' ELSE E'\n' END,
        '[Migrated from annual_reconciliation; excluded from active usage replay.]'
    )
WHERE source_type = 'annual_reconciliation';

ALTER TABLE leave_usage_records
    DROP COLUMN reconciliation_set_id;

DROP TABLE leave_usage_reconciliation_sets;

DROP FUNCTION IF EXISTS validate_leave_usage_membership();
DROP FUNCTION IF EXISTS guard_leave_usage_membership_history();
DROP FUNCTION IF EXISTS guard_leave_usage_reconciliation_update();
DROP FUNCTION IF EXISTS guard_leave_usage_reconciliation_history();
DROP FUNCTION IF EXISTS enforce_leave_usage_reconciliation_lifecycle_contract();

ALTER TABLE leave_usage_records
    ADD CONSTRAINT leave_usage_source_type_check
        CHECK (source_type IN ('approved_request', 'manual_external')),
    ADD CONSTRAINT leave_usage_workdays_check
        CHECK (workdays > 0),
    ADD CONSTRAINT leave_usage_source_contract_check
        CHECK (
            (source_type = 'approved_request'
                AND leave_request_id IS NOT NULL
                AND start_date IS NOT NULL
                AND end_date IS NOT NULL)
            OR
            (source_type = 'manual_external'
                AND leave_request_id IS NULL
                AND start_date IS NOT NULL
                AND end_date IS NOT NULL)
        );

CREATE TRIGGER leave_usage_document_restrict_update
BEFORE UPDATE ON leave_usage_documents
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_document_update();

CREATE TRIGGER leave_usage_record_restrict_update
BEFORE UPDATE ON leave_usage_records
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_record_update();
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_leave_usage_record_lifecycle_contract() RETURNS trigger AS $$
DECLARE
    effects jsonb;
    replacement_id uuid;
    terminal_event text;
BEGIN
    IF OLD.source_type <> 'manual_external' THEN
        RAISE EXCEPTION 'lifecycle terminal hanya berlaku untuk fakta manual_external';
    END IF;

    IF NEW.record_status = 'superseded' THEN
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

CREATE CONSTRAINT TRIGGER leave_usage_record_lifecycle_contract
AFTER UPDATE OF record_status ON leave_usage_records
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
WHEN (OLD.record_status IS DISTINCT FROM NEW.record_status)
EXECUTE FUNCTION enforce_leave_usage_record_lifecycle_contract();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        /*
         * Struktur ini hanya dipulihkan agar migrasi historis dapat diturunkan
         * berurutan oleh DatabaseMigrations. `up()` menolak data legacy, jadi
         * rollback tidak pernah merekonstruksi data atau mengaktifkan kembali
         * domain rekonsiliasi pada runtime aplikasi.
         */
        Schema::create('leave_usage_reconciliation_sets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->year('balance_year');
            $table->date('reconciled_at');
            $table->string('status', 20)->default('active');
            $table->uuid('replaces_id')->nullable();
            $table->text('administrative_note');
            $table->text('correction_reason')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'balance_year'], 'leave_usage_reconciliation_employee_year_index');
        });
        Schema::table('leave_usage_reconciliation_sets', function (Blueprint $table): void {
            $table->foreign('replaces_id')
                ->references('id')
                ->on('leave_usage_reconciliation_sets')
                ->restrictOnDelete();
        });

        Schema::table('leave_usage_records', function (Blueprint $table): void {
            $table->uuid('reconciliation_set_id')->nullable();
            $table->foreign('reconciliation_set_id')
                ->references('id')
                ->on('leave_usage_reconciliation_sets')
                ->restrictOnDelete();
            $table->index(['reconciliation_set_id', 'usage_year'], 'leave_usage_reconciliation_year_index');
            $table->unique(['reconciliation_set_id', 'usage_year'], 'leave_usage_reconciliation_year_unique');
        });

        Schema::table('leave_usage_documents', function (Blueprint $table): void {
            $table->uuid('leave_usage_reconciliation_set_id')->nullable();
            $table->foreign('leave_usage_reconciliation_set_id', 'leave_usage_documents_leave_usage_reconciliation_set_id_foreign')
                ->references('id')
                ->on('leave_usage_reconciliation_sets')
                ->restrictOnDelete();
            $table->index('leave_usage_reconciliation_set_id', 'leave_usage_documents_reconciliation_set_index');
        });

        Schema::create('leave_usage_reconciliation_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reconciliation_set_id')->constrained('leave_usage_reconciliation_sets')->restrictOnDelete();
            $table->foreignUuid('annual_reconciliation_record_id')->constrained('leave_usage_records')->restrictOnDelete();
            $table->foreignUuid('itemized_usage_record_id')->constrained('leave_usage_records')->restrictOnDelete();
            $table->unsignedSmallInteger('included_workdays');
            $table->timestamps();

            $table->unique(
                ['reconciliation_set_id', 'itemized_usage_record_id'],
                'leave_usage_membership_set_item_unique',
            );
            $table->index(
                ['annual_reconciliation_record_id', 'itemized_usage_record_id'],
                'leave_usage_membership_replay_index',
            );
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE leave_usage_records
    DROP CONSTRAINT IF EXISTS leave_usage_source_type_check,
    DROP CONSTRAINT IF EXISTS leave_usage_workdays_check,
    DROP CONSTRAINT IF EXISTS leave_usage_source_contract_check;
ALTER TABLE leave_usage_records
    ADD CONSTRAINT leave_usage_source_type_check
        CHECK (source_type IN ('annual_reconciliation', 'approved_request', 'manual_external')),
    ADD CONSTRAINT leave_usage_workdays_check
        CHECK ((source_type = 'annual_reconciliation' AND workdays >= 0) OR (source_type <> 'annual_reconciliation' AND workdays > 0)),
    ADD CONSTRAINT leave_usage_source_contract_check
        CHECK ((source_type = 'annual_reconciliation' AND reconciliation_set_id IS NOT NULL AND leave_request_id IS NULL AND leave_request_case_id IS NULL AND start_date IS NULL AND end_date IS NULL) OR (source_type = 'approved_request' AND reconciliation_set_id IS NULL AND leave_request_id IS NOT NULL AND start_date IS NOT NULL AND end_date IS NOT NULL) OR (source_type = 'manual_external' AND reconciliation_set_id IS NULL AND leave_request_id IS NULL AND start_date IS NOT NULL AND end_date IS NOT NULL));

ALTER TABLE leave_usage_documents
    DROP CONSTRAINT IF EXISTS leave_usage_documents_target_check;
ALTER TABLE leave_usage_documents
    ADD CONSTRAINT leave_usage_documents_target_check
        CHECK ((leave_usage_record_id IS NOT NULL)::integer + (leave_usage_reconciliation_set_id IS NOT NULL)::integer = 1);
SQL);
    }
};
