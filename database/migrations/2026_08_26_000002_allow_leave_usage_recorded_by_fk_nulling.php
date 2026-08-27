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

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
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
SQL);
    }
};
