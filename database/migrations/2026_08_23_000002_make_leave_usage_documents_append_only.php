<?php

use Illuminate\Database\Migrations\Migration;
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
CREATE OR REPLACE FUNCTION guard_leave_usage_document_update() RETURNS trigger AS $$
BEGIN
    -- FK uploader boleh kehilangan UUID ketika user dihapus, tanpa menulis ulang bukti historis lain.
    IF OLD.uploaded_by IS NOT NULL
        AND NEW.uploaded_by IS NULL
        AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.uploaded_by)
        AND OLD.id IS NOT DISTINCT FROM NEW.id
        AND OLD.leave_usage_record_id IS NOT DISTINCT FROM NEW.leave_usage_record_id
        AND OLD.leave_usage_reconciliation_set_id IS NOT DISTINCT FROM NEW.leave_usage_reconciliation_set_id
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

CREATE OR REPLACE FUNCTION guard_leave_usage_document_history() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'metadata dokumen pemakaian cuti bersifat append-only dan tidak dapat dihapus';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER leave_usage_document_restrict_update
BEFORE UPDATE ON leave_usage_documents
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_document_update();

CREATE TRIGGER leave_usage_document_no_delete
BEFORE DELETE ON leave_usage_documents
FOR EACH ROW EXECUTE FUNCTION guard_leave_usage_document_history();

CREATE TRIGGER leave_usage_document_no_truncate
BEFORE TRUNCATE ON leave_usage_documents
FOR EACH STATEMENT EXECUTE FUNCTION guard_leave_usage_document_history();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (Schema::hasTable('leave_usage_documents')) {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_usage_document_restrict_update ON leave_usage_documents;
DROP TRIGGER IF EXISTS leave_usage_document_no_delete ON leave_usage_documents;
DROP TRIGGER IF EXISTS leave_usage_document_no_truncate ON leave_usage_documents;
SQL);
        }

        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS guard_leave_usage_document_update();
DROP FUNCTION IF EXISTS guard_leave_usage_document_history();
SQL);
    }
};
