<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE leave_usage_reconciliation_sets ADD CONSTRAINT leave_usage_reconciliation_status_check CHECK (status IN ('active', 'superseded'))");
        DB::statement('ALTER TABLE leave_usage_reconciliation_sets ADD CONSTRAINT leave_usage_reconciliation_year_check CHECK (balance_year BETWEEN 1900 AND 2100)');
        DB::statement("ALTER TABLE leave_usage_reconciliation_sets ADD CONSTRAINT leave_usage_reconciliation_correction_check CHECK ((replaces_id IS NULL AND correction_reason IS NULL) OR (replaces_id IS NOT NULL AND NULLIF(BTRIM(correction_reason), '') IS NOT NULL))");
        DB::statement("CREATE UNIQUE INDEX leave_usage_reconciliation_one_active_per_employee ON leave_usage_reconciliation_sets (employee_id) WHERE status = 'active'");
        DB::statement('CREATE UNIQUE INDEX leave_usage_reconciliation_replaces_unique ON leave_usage_reconciliation_sets (replaces_id) WHERE replaces_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_leave_usage_reconciliation_history() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'set rekonsiliasi pemakaian cuti bersifat historis dan tidak dapat dihapus';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER leave_usage_reconciliation_no_delete
BEFORE DELETE ON leave_usage_reconciliation_sets
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_reconciliation_history();

CREATE TRIGGER leave_usage_reconciliation_no_truncate
BEFORE TRUNCATE ON leave_usage_reconciliation_sets
FOR EACH STATEMENT EXECUTE FUNCTION guard_leave_usage_reconciliation_history();

CREATE OR REPLACE FUNCTION guard_leave_usage_reconciliation_update() RETURNS trigger AS $$
BEGIN
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

CREATE TRIGGER leave_usage_reconciliation_restrict_update
BEFORE UPDATE ON leave_usage_reconciliation_sets
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_reconciliation_update();

SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_usage_reconciliation_no_delete ON leave_usage_reconciliation_sets;
DROP TRIGGER IF EXISTS leave_usage_reconciliation_no_truncate ON leave_usage_reconciliation_sets;
DROP TRIGGER IF EXISTS leave_usage_reconciliation_restrict_update ON leave_usage_reconciliation_sets;
DROP FUNCTION IF EXISTS guard_leave_usage_reconciliation_update();
DROP FUNCTION IF EXISTS guard_leave_usage_reconciliation_history();
DROP INDEX IF EXISTS leave_usage_reconciliation_one_active_per_employee;
DROP INDEX IF EXISTS leave_usage_reconciliation_replaces_unique;
SQL);
        }

        Schema::dropIfExists('leave_usage_reconciliation_sets');
    }
};
