<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_usage_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->restrictOnDelete();
            $table->foreignUuid('leave_type_id')->constrained('ref_jenis_cuti')->restrictOnDelete();
            $table->string('source_type', 40);
            $table->foreignUuid('reconciliation_set_id')->nullable()->constrained('leave_usage_reconciliation_sets')->restrictOnDelete();
            $table->foreignUuid('leave_request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();
            $table->foreignUuid('leave_request_case_id')->nullable()->constrained('leave_request_cases')->restrictOnDelete();
            $table->year('usage_year');
            $table->date('effective_date');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedSmallInteger('workdays');
            $table->text('administrative_note');
            $table->string('record_status', 20)->default('active');
            $table->uuid('replaces_id')->nullable();
            $table->text('correction_reason')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'usage_year', 'effective_date', 'created_at', 'id'], 'leave_usage_replay_order_index');
            $table->index(['reconciliation_set_id', 'usage_year'], 'leave_usage_reconciliation_year_index');
            $table->unique(['reconciliation_set_id', 'usage_year'], 'leave_usage_reconciliation_year_unique');
            $table->unique('leave_request_id', 'leave_usage_leave_request_unique');
        });
        Schema::table('leave_usage_records', function (Blueprint $table): void {
            $table->foreign('replaces_id')
                ->references('id')
                ->on('leave_usage_records')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE leave_usage_records ADD CONSTRAINT leave_usage_source_type_check CHECK (source_type IN ('annual_reconciliation', 'approved_request', 'manual_external'))");
        DB::statement("ALTER TABLE leave_usage_records ADD CONSTRAINT leave_usage_record_status_check CHECK (record_status IN ('active', 'superseded', 'cancelled'))");
        DB::statement('ALTER TABLE leave_usage_records ADD CONSTRAINT leave_usage_year_check CHECK (usage_year BETWEEN 1900 AND 2100)');
        DB::statement("ALTER TABLE leave_usage_records ADD CONSTRAINT leave_usage_workdays_check CHECK ((source_type = 'annual_reconciliation' AND workdays >= 0) OR (source_type <> 'annual_reconciliation' AND workdays > 0))");
        DB::statement("ALTER TABLE leave_usage_records ADD CONSTRAINT leave_usage_source_contract_check CHECK ((source_type = 'annual_reconciliation' AND reconciliation_set_id IS NOT NULL AND leave_request_id IS NULL AND leave_request_case_id IS NULL AND start_date IS NULL AND end_date IS NULL) OR (source_type = 'approved_request' AND reconciliation_set_id IS NULL AND leave_request_id IS NOT NULL AND start_date IS NOT NULL AND end_date IS NOT NULL) OR (source_type = 'manual_external' AND reconciliation_set_id IS NULL AND leave_request_id IS NULL AND start_date IS NOT NULL AND end_date IS NOT NULL))");
        DB::statement('ALTER TABLE leave_usage_records ADD CONSTRAINT leave_usage_period_check CHECK ((start_date IS NULL AND end_date IS NULL) OR (start_date IS NOT NULL AND end_date IS NOT NULL AND end_date >= start_date AND EXTRACT(YEAR FROM start_date)::integer = usage_year AND EXTRACT(YEAR FROM end_date)::integer = usage_year))');
        DB::statement('ALTER TABLE leave_usage_records ADD CONSTRAINT leave_usage_effective_year_check CHECK (EXTRACT(YEAR FROM effective_date)::integer = usage_year)');
        DB::statement("ALTER TABLE leave_usage_records ADD CONSTRAINT leave_usage_correction_check CHECK ((replaces_id IS NULL OR NULLIF(BTRIM(correction_reason), '') IS NOT NULL) AND (record_status = 'active' OR NULLIF(BTRIM(correction_reason), '') IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX leave_usage_manual_exact_active_unique ON leave_usage_records (employee_id, leave_type_id, start_date, end_date) WHERE source_type = 'manual_external' AND record_status = 'active'");
        DB::statement("CREATE INDEX leave_usage_manual_case_period_index ON leave_usage_records (leave_request_case_id, start_date, end_date) WHERE source_type = 'manual_external' AND record_status = 'active' AND leave_request_case_id IS NOT NULL");
        DB::statement('CREATE UNIQUE INDEX leave_usage_replaces_unique ON leave_usage_records (replaces_id) WHERE replaces_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_leave_usage_record_history() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'fakta pemakaian cuti bersifat historis dan tidak dapat dihapus';
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION guard_leave_usage_record_update() RETURNS trigger AS $$
BEGIN
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

CREATE TRIGGER leave_usage_record_no_delete
BEFORE DELETE ON leave_usage_records
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_record_history();

CREATE TRIGGER leave_usage_record_restrict_update
BEFORE UPDATE ON leave_usage_records
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_record_update();

CREATE TRIGGER leave_usage_record_no_truncate
BEFORE TRUNCATE ON leave_usage_records
FOR EACH STATEMENT EXECUTE FUNCTION guard_leave_usage_record_history();

SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_usage_record_no_delete ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_record_restrict_update ON leave_usage_records;
DROP TRIGGER IF EXISTS leave_usage_record_no_truncate ON leave_usage_records;
DROP FUNCTION IF EXISTS guard_leave_usage_record_update();
DROP FUNCTION IF EXISTS guard_leave_usage_record_history();
DROP INDEX IF EXISTS leave_usage_manual_exact_active_unique;
DROP INDEX IF EXISTS leave_usage_manual_case_period_index;
DROP INDEX IF EXISTS leave_usage_replaces_unique;
SQL);
        }

        Schema::dropIfExists('leave_usage_records');
    }
};
