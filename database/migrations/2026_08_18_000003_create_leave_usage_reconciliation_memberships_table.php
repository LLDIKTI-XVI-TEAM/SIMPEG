<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE leave_usage_reconciliation_memberships ADD CONSTRAINT leave_usage_membership_workdays_check CHECK (included_workdays > 0)');
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION validate_leave_usage_membership() RETURNS trigger AS $$
DECLARE
    set_employee uuid;
    annual_employee uuid;
    annual_set uuid;
    annual_year integer;
    annual_source varchar;
    item_employee uuid;
    item_year integer;
    item_source varchar;
    item_status varchar;
    item_workdays integer;
BEGIN
    SELECT employee_id INTO set_employee
    FROM leave_usage_reconciliation_sets
    WHERE id = NEW.reconciliation_set_id;

    SELECT employee_id, reconciliation_set_id, usage_year, source_type
    INTO annual_employee, annual_set, annual_year, annual_source
    FROM leave_usage_records
    WHERE id = NEW.annual_reconciliation_record_id;

    SELECT employee_id, usage_year, source_type, record_status, workdays
    INTO item_employee, item_year, item_source, item_status, item_workdays
    FROM leave_usage_records
    WHERE id = NEW.itemized_usage_record_id;

    IF annual_source <> 'annual_reconciliation'
        OR annual_set <> NEW.reconciliation_set_id
        OR item_source = 'annual_reconciliation'
        OR item_status <> 'active'
        OR set_employee <> annual_employee
        OR set_employee <> item_employee
        OR annual_year <> item_year
        OR NEW.included_workdays <> item_workdays THEN
        RAISE EXCEPTION 'membership rekonsiliasi tidak konsisten dengan set, tahun, atau fakta itemized';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER leave_usage_membership_validate
BEFORE INSERT ON leave_usage_reconciliation_memberships
FOR EACH ROW EXECUTE FUNCTION validate_leave_usage_membership();

CREATE OR REPLACE FUNCTION guard_leave_usage_membership_history() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'membership rekonsiliasi bersifat immutable dan tidak dapat diubah atau dihapus';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER leave_usage_membership_no_update_delete
BEFORE UPDATE OR DELETE ON leave_usage_reconciliation_memberships
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_membership_history();

CREATE TRIGGER leave_usage_membership_no_truncate
BEFORE TRUNCATE ON leave_usage_reconciliation_memberships
FOR EACH STATEMENT EXECUTE FUNCTION guard_leave_usage_membership_history();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_usage_membership_validate ON leave_usage_reconciliation_memberships;
DROP TRIGGER IF EXISTS leave_usage_membership_no_update_delete ON leave_usage_reconciliation_memberships;
DROP TRIGGER IF EXISTS leave_usage_membership_no_truncate ON leave_usage_reconciliation_memberships;
DROP FUNCTION IF EXISTS validate_leave_usage_membership();
DROP FUNCTION IF EXISTS guard_leave_usage_membership_history();
SQL);
        }

        Schema::dropIfExists('leave_usage_reconciliation_memberships');
    }
};
