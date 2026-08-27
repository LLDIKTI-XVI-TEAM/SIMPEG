<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_usage_records', function (Blueprint $table): void {
            $table->string('approval_document_number', 255)->nullable();
        });

        Schema::create('leave_usage_external_approval_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('leave_usage_record_id')
                ->constrained('leave_usage_records')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('step_order');
            $table->string('step_type', 32);
            $table->string('approver_source', 32);
            $table->foreignUuid('approver_employee_id')
                ->nullable()
                ->constrained('employees')
                ->restrictOnDelete();
            $table->string('approver_name_snapshot', 255);
            $table->string('approver_nip_snapshot', 18)->nullable();
            $table->string('approver_position_snapshot', 255)->nullable();
            $table->string('approver_institution_snapshot', 255);
            $table->date('acted_on');
            $table->string('result_code', 32);
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->unique(
                ['leave_usage_record_id', 'step_order'],
                'leave_usage_external_approval_record_order_unique',
            );
            $table->index('approver_employee_id', 'leave_usage_external_approval_employee_index');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE leave_usage_external_approval_steps
    ADD CONSTRAINT leave_usage_external_approval_order_check
        CHECK (step_order BETWEEN 1 AND 10),
    ADD CONSTRAINT leave_usage_external_approval_type_check
        CHECK (step_type IN ('verifier', 'kepala_bagian', 'pybmc')),
    ADD CONSTRAINT leave_usage_external_approval_source_check
        CHECK (approver_source IN ('simpeg_employee', 'external_official')),
    ADD CONSTRAINT leave_usage_external_approval_result_check
        CHECK (
            (step_type = 'verifier' AND result_code = 'verified')
            OR (step_type = 'kepala_bagian' AND result_code = 'approved')
            OR (step_type = 'pybmc' AND result_code = 'final_approved')
        ),
    ADD CONSTRAINT leave_usage_external_approval_name_check
        CHECK (NULLIF(BTRIM(translate(approver_name_snapshot, U&'\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000', repeat(' ', 19))), '') IS NOT NULL),
    ADD CONSTRAINT leave_usage_external_approval_identity_check
        CHECK (
            (
                approver_source = 'simpeg_employee'
                AND approver_employee_id IS NOT NULL
                AND approver_institution_snapshot = 'LLDIKTI Wilayah XVI'
            )
            OR (
                approver_source = 'external_official'
                AND approver_employee_id IS NULL
                AND NULLIF(BTRIM(translate(approver_position_snapshot, U&'\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000', repeat(' ', 19))), '') IS NOT NULL
                AND NULLIF(BTRIM(translate(approver_institution_snapshot, U&'\00A0\1680\2000\2001\2002\2003\2004\2005\2006\2007\2008\2009\200A\2028\2029\202F\205F\3000', repeat(' ', 19))), '') IS NOT NULL
            )
        );

CREATE UNIQUE INDEX leave_usage_external_approval_one_kepala_bagian_unique
    ON leave_usage_external_approval_steps (leave_usage_record_id)
    WHERE step_type = 'kepala_bagian';

CREATE UNIQUE INDEX leave_usage_external_approval_one_pybmc_unique
    ON leave_usage_external_approval_steps (leave_usage_record_id)
    WHERE step_type = 'pybmc';

CREATE OR REPLACE FUNCTION validate_leave_usage_external_approval_chain(record_id uuid)
RETURNS void
LANGUAGE plpgsql
AS $$
DECLARE
    parent_source text;
    total_steps integer;
    verifier_count integer;
    kepala_bagian_count integer;
    pybmc_count integer;
    kepala_bagian_order integer;
    pybmc_order integer;
    distinct_orders integer;
    minimum_order integer;
    maximum_order integer;
    invalid_verifier_count integer;
BEGIN
    SELECT source_type INTO parent_source
    FROM leave_usage_records
    WHERE id = record_id;

    IF parent_source IS NULL THEN
        RETURN;
    END IF;

    IF parent_source <> 'manual_external' THEN
        RAISE EXCEPTION 'leave_usage_external_approval hanya boleh terkait parent manual_external';
    END IF;

    SELECT
        COUNT(*),
        COUNT(*) FILTER (WHERE step_type = 'verifier'),
        COUNT(*) FILTER (WHERE step_type = 'kepala_bagian'),
        COUNT(*) FILTER (WHERE step_type = 'pybmc'),
        MIN(step_order) FILTER (WHERE step_type = 'kepala_bagian'),
        MIN(step_order) FILTER (WHERE step_type = 'pybmc'),
        COUNT(DISTINCT step_order),
        MIN(step_order),
        MAX(step_order)
    INTO
        total_steps,
        verifier_count,
        kepala_bagian_count,
        pybmc_count,
        kepala_bagian_order,
        pybmc_order,
        distinct_orders,
        minimum_order,
        maximum_order
    FROM leave_usage_external_approval_steps
    WHERE leave_usage_record_id = record_id;

    SELECT COUNT(*) INTO invalid_verifier_count
    FROM leave_usage_external_approval_steps
    WHERE leave_usage_record_id = record_id
      AND step_type = 'verifier'
      AND step_order >= kepala_bagian_order;

    IF total_steps < 2
        OR total_steps > 10
        OR verifier_count > 8
        OR kepala_bagian_count <> 1
        OR pybmc_count <> 1
        OR minimum_order <> 1
        OR maximum_order <> total_steps
        OR distinct_orders <> total_steps
        OR kepala_bagian_order >= pybmc_order
        OR pybmc_order <> total_steps
        OR invalid_verifier_count > 0
    THEN
        RAISE EXCEPTION 'leave_usage_external_approval snapshot persetujuan wajib lengkap, kontigu, dan berurutan';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION enforce_leave_usage_external_approval_parent()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM validate_leave_usage_external_approval_chain(NEW.id);
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER leave_usage_external_approval_parent_complete
AFTER INSERT ON leave_usage_records
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
WHEN (NEW.source_type = 'manual_external')
EXECUTE FUNCTION enforce_leave_usage_external_approval_parent();

CREATE OR REPLACE FUNCTION enforce_leave_usage_external_approval_step()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    PERFORM validate_leave_usage_external_approval_chain(NEW.leave_usage_record_id);
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER leave_usage_external_approval_step_complete
AFTER INSERT ON leave_usage_external_approval_steps
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION enforce_leave_usage_external_approval_step();

CREATE OR REPLACE FUNCTION reject_leave_usage_external_approval_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'leave_usage_external_approval snapshot bersifat append-only';
END;
$$;

CREATE TRIGGER leave_usage_external_approval_no_mutation
BEFORE UPDATE OR DELETE ON leave_usage_external_approval_steps
FOR EACH ROW
EXECUTE FUNCTION reject_leave_usage_external_approval_mutation();

CREATE TRIGGER leave_usage_external_approval_no_truncate
BEFORE TRUNCATE ON leave_usage_external_approval_steps
FOR EACH STATEMENT
EXECUTE FUNCTION reject_leave_usage_external_approval_mutation();

CREATE OR REPLACE FUNCTION reject_leave_usage_source_type_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'leave_usage_records source_type bersifat immutable';
END;
$$;

CREATE TRIGGER leave_usage_record_source_type_immutable
BEFORE UPDATE OF source_type ON leave_usage_records
FOR EACH ROW
WHEN (OLD.source_type IS DISTINCT FROM NEW.source_type)
EXECUTE FUNCTION reject_leave_usage_source_type_mutation();

CREATE OR REPLACE FUNCTION reject_leave_usage_approval_document_number_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    -- Nomor dokumen adalah bagian dari fakta historis; koreksi harus membuat fakta pengganti.
    RAISE EXCEPTION 'kolom substantif bersifat immutable pada fakta pemakaian cuti';
END;
$$;

CREATE TRIGGER leave_usage_record_approval_document_number_immutable
BEFORE UPDATE OF approval_document_number ON leave_usage_records
FOR EACH ROW
WHEN (OLD.approval_document_number IS DISTINCT FROM NEW.approval_document_number)
EXECUTE FUNCTION reject_leave_usage_approval_document_number_mutation();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_usage_record_approval_document_number_immutable ON leave_usage_records;
DROP FUNCTION IF EXISTS reject_leave_usage_approval_document_number_mutation();
DROP TRIGGER IF EXISTS leave_usage_record_source_type_immutable ON leave_usage_records;
DROP FUNCTION IF EXISTS reject_leave_usage_source_type_mutation();
DROP TRIGGER IF EXISTS leave_usage_external_approval_parent_complete ON leave_usage_records;
DROP FUNCTION IF EXISTS enforce_leave_usage_external_approval_parent();
DROP TRIGGER IF EXISTS leave_usage_external_approval_step_complete ON leave_usage_external_approval_steps;
DROP FUNCTION IF EXISTS enforce_leave_usage_external_approval_step();
DROP TRIGGER IF EXISTS leave_usage_external_approval_no_mutation ON leave_usage_external_approval_steps;
DROP TRIGGER IF EXISTS leave_usage_external_approval_no_truncate ON leave_usage_external_approval_steps;
DROP FUNCTION IF EXISTS reject_leave_usage_external_approval_mutation();
DROP FUNCTION IF EXISTS validate_leave_usage_external_approval_chain(uuid);
SQL);

        Schema::dropIfExists('leave_usage_external_approval_steps');

        Schema::table('leave_usage_records', function (Blueprint $table): void {
            $table->dropColumn('approval_document_number');
        });
    }
};
