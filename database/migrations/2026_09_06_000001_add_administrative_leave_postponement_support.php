<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $previousStatuses = [
        'menunggu_approval',
        'ditangguhkan',
        'ditangguhkan_tugas_dinas',
        'perlu_perubahan',
        'disetujui',
        'tidak_disetujui',
        'dikembalikan_karena_rollover',
        'menunggu_pembatalan',
        'dibatalkan',
    ];

    /** Keputusan administratif tidak mengubah snapshot persetujuan atau menciptakan workflow baru. */
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->timestamp('administratively_postponed_at')->nullable();
            $table->foreignUuid('administratively_postponed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('administrative_postponement_reason')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceStatusConstraint([...$this->previousStatuses, 'ditangguhkan_administratif']);
        DB::unprepared(<<<'SQL'
ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_administrative_metadata_check CHECK (
    (status = 'ditangguhkan_administratif'
        AND administratively_postponed_at IS NOT NULL
        AND administrative_postponement_reason IS NOT NULL
        AND NULLIF(BTRIM(administrative_postponement_reason), '') IS NOT NULL
        AND CHAR_LENGTH(administrative_postponement_reason) <= 500)
    OR (status <> 'ditangguhkan_administratif'
        AND administratively_postponed_at IS NULL
        AND administratively_postponed_by IS NULL
        AND administrative_postponement_reason IS NULL)
);

CREATE OR REPLACE FUNCTION guard_leave_request_administrative_postponement() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status = 'ditangguhkan_administratif' THEN
            RAISE EXCEPTION 'keputusan penangguhan administratif bersifat immutable';
        END IF;
        RETURN OLD;
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.status = 'ditangguhkan_administratif' THEN
            RAISE EXCEPTION 'penangguhan administratif hanya boleh berasal dari pengajuan disetujui';
        END IF;
        RETURN NEW;
    END IF;

    IF OLD.status = 'ditangguhkan_administratif' THEN
        -- FK boleh kehilangan aktor yang benar-benar dihapus, tanpa membuka perubahan histori lain.
        IF OLD.administratively_postponed_by IS NOT NULL
            AND NEW.administratively_postponed_by IS NULL
            AND NOT EXISTS (SELECT 1 FROM users WHERE id = OLD.administratively_postponed_by)
            AND (to_jsonb(OLD) - 'administratively_postponed_by')
                IS NOT DISTINCT FROM (to_jsonb(NEW) - 'administratively_postponed_by')
        THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'keputusan penangguhan administratif dan snapshot pengajuan bersifat immutable';
    END IF;

    IF NEW.status = 'ditangguhkan_administratif' THEN
        IF OLD.status <> 'disetujui'
            OR NEW.administratively_postponed_at IS NULL
            OR NEW.administratively_postponed_by IS NULL
            OR (to_jsonb(OLD) - ARRAY['status', 'administratively_postponed_at',
                'administratively_postponed_by', 'administrative_postponement_reason', 'updated_at'])
                IS DISTINCT FROM (to_jsonb(NEW) - ARRAY['status', 'administratively_postponed_at',
                'administratively_postponed_by', 'administrative_postponement_reason', 'updated_at'])
        THEN
            RAISE EXCEPTION 'penangguhan administratif wajib berasal dari pengajuan disetujui tanpa mengubah snapshot';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER leave_request_administrative_postponement_guard
BEFORE INSERT OR UPDATE OR DELETE ON leave_requests
FOR EACH ROW EXECUTE FUNCTION guard_leave_request_administrative_postponement();

CREATE OR REPLACE FUNCTION enforce_leave_request_administrative_reversal() RETURNS trigger AS $$
BEGIN
    -- Status ditulis sebelum reversal; keduanya wajib lengkap saat transaksi hendak commit.
    -- Efek ledger/audit tetap diperiksa oleh constraint lifecycle pada fakta yang dibatalkan.
    IF NOT EXISTS (
        SELECT 1 FROM leave_usage_records AS fact
        WHERE fact.leave_request_id = NEW.id
          AND fact.source_type = 'approved_request'
          AND fact.record_status = 'cancelled'
          AND fact.employee_id = NEW.employee_id
          AND fact.leave_type_id = NEW.jenis_cuti_id
          AND fact.leave_request_case_id IS NOT DISTINCT FROM NEW.leave_request_case_id
          AND fact.start_date = NEW.tanggal_mulai
          AND fact.end_date = NEW.tanggal_selesai
          AND fact.effective_date = NEW.tanggal_mulai
          AND fact.usage_year = EXTRACT(YEAR FROM NEW.tanggal_mulai)::integer
          AND fact.workdays = NEW.jumlah_hari_kerja
          AND fact.workdays > 0
    ) THEN
        RAISE EXCEPTION 'penangguhan administratif wajib memiliki fakta approved yang dibatalkan sesuai pengajuan';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER leave_request_administrative_reversal_required
AFTER UPDATE ON leave_requests DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
WHEN (OLD.status IS DISTINCT FROM NEW.status AND NEW.status = 'ditangguhkan_administratif')
EXECUTE FUNCTION enforce_leave_request_administrative_reversal();

CREATE OR REPLACE FUNCTION guard_approved_leave_usage_insert() RETURNS trigger AS $$
BEGIN
    -- Fakta approved harus melewati UPDATE lifecycle agar efek reversal tidak dapat dilewati lewat INSERT terminal.
    IF NEW.source_type = 'approved_request' AND NEW.record_status <> 'active' THEN
        RAISE EXCEPTION 'fakta approved wajib dicatat aktif sebelum reversal resmi';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER approved_leave_usage_insert_guard
BEFORE INSERT ON leave_usage_records
FOR EACH ROW EXECUTE FUNCTION guard_approved_leave_usage_insert();
SQL);
        $this->installUsageGuard(true);
    }

    /** Rollback hanya tersedia sebelum keputusan baru menjadi jejak resmi yang harus dipertahankan. */
    public function down(): void
    {
        if (DB::table('leave_requests')
            ->where('status', 'ditangguhkan_administratif')
            ->orWhereNotNull('administratively_postponed_at')
            ->orWhereNotNull('administratively_postponed_by')
            ->orWhereNotNull('administrative_postponement_reason')->exists()
            || DB::table('leave_usage_records')->where('source_type', 'approved_request')
                ->where('record_status', 'cancelled')->exists()
            || DB::table('audit_logs')->where('auditable_type', 'LeaveRequest')
                ->where('new_values->operation', 'administrative_postponement')->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena jejak penangguhan administratif sudah tercatat.');
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->installUsageGuard(false);
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_request_administrative_postponement_guard ON leave_requests;
DROP FUNCTION IF EXISTS guard_leave_request_administrative_postponement();
DROP TRIGGER IF EXISTS leave_request_administrative_reversal_required ON leave_requests;
DROP FUNCTION IF EXISTS enforce_leave_request_administrative_reversal();
DROP TRIGGER IF EXISTS approved_leave_usage_insert_guard ON leave_usage_records;
DROP FUNCTION IF EXISTS guard_approved_leave_usage_insert();
ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_administrative_metadata_check;
SQL);
            $this->replaceStatusConstraint($this->previousStatuses);
        }

        Schema::table('leave_requests', function (Blueprint $table): void {
            $table->dropForeign(['administratively_postponed_by']);
            $table->dropColumn([
                'administratively_postponed_at',
                'administratively_postponed_by',
                'administrative_postponement_reason',
            ]);
        });
    }

    /** @param list<string> $statuses */
    private function replaceStatusConstraint(array $statuses): void
    {
        $quoted = collect($statuses)->map(fn (string $status): string => "'{$status}'")->implode(', ');
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT IF EXISTS leave_requests_status_check');
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_status_check CHECK (status IN ({$quoted}))");
    }

    /**
     * Guard immediate dan deferred memakai kontrak pembatalan approved yang sama.
     * Lineage manual, ledger/audit satu transaksi, serta FK nulling tetap berlaku saat up maupun down.
     */
    private function installUsageGuard(bool $allowAdministrativeCancellation): void
    {
        $approvedGuard = $allowAdministrativeCancellation ? <<<'SQL'
        IF NEW.record_status <> 'cancelled' OR NOT EXISTS (
            SELECT 1 FROM leave_requests AS source
            WHERE source.id = OLD.leave_request_id
              AND source.status = 'ditangguhkan_administratif'
              AND source.administratively_postponed_at IS NOT NULL
              AND source.employee_id = OLD.employee_id
              AND source.jenis_cuti_id = OLD.leave_type_id
              AND source.leave_request_case_id IS NOT DISTINCT FROM OLD.leave_request_case_id
              AND source.tanggal_mulai = OLD.start_date
              AND source.tanggal_selesai = OLD.end_date
              AND source.tanggal_mulai = OLD.effective_date
              AND EXTRACT(YEAR FROM source.tanggal_mulai)::integer = OLD.usage_year
              AND source.jumlah_hari_kerja = OLD.workdays
              AND OLD.workdays > 0
        ) THEN
            RAISE EXCEPTION 'lifecycle fakta approved_request bersifat immutable di luar penangguhan administratif sumber yang cocok';
        END IF;
SQL : <<<'SQL'
        RAISE EXCEPTION 'lifecycle fakta approved_request bersifat immutable';
SQL;

        DB::unprepared(<<<SQL
CREATE OR REPLACE FUNCTION guard_leave_usage_record_update() RETURNS trigger AS $$
BEGIN
    -- ON DELETE SET NULL hanya boleh mengosongkan aktor, dengan seluruh payload historis tetap.
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
        OR OLD.created_at IS DISTINCT FROM NEW.created_at
    THEN
        RAISE EXCEPTION 'kolom substantif bersifat immutable pada fakta pemakaian cuti';
    END IF;

    IF OLD.record_status <> 'active' THEN
        RAISE EXCEPTION 'lifecycle terminal bersifat immutable pada fakta pemakaian cuti';
    END IF;

    IF OLD.source_type = 'approved_request' THEN
{$approvedGuard}
    END IF;

    IF NEW.record_status NOT IN ('superseded', 'cancelled')
        OR NULLIF(BTRIM(NEW.correction_reason), '') IS NULL THEN
        RAISE EXCEPTION 'fakta aktif hanya dapat ditutup sebagai superseded atau cancelled dengan alasan';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE OR REPLACE FUNCTION enforce_leave_usage_record_lifecycle_contract() RETURNS trigger AS $$
DECLARE
    effects jsonb;
    replacement_id uuid;
    terminal_event text;
BEGIN
    IF OLD.source_type = 'approved_request' THEN
{$approvedGuard}
    ELSIF OLD.source_type <> 'manual_external' THEN
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
SQL);
    }
};
