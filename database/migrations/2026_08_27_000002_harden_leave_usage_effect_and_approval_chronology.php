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
CREATE OR REPLACE FUNCTION remember_leave_usage_lifecycle_effect() RETURNS trigger AS $$
DECLARE
    effects jsonb;
    effect_key text;
    usage_record_id text;
BEGIN
    effects := COALESCE(
        NULLIF(current_setting('simpeg.leave_usage_lifecycle_effects', true), ''),
        '{}'
    )::jsonb;

    IF TG_TABLE_NAME = 'leave_balance_ledger' THEN
        usage_record_id := NULLIF(NEW.metadata->>'usage_record_id', '');

        -- Event ledger umum tetap sah tanpa referensi fakta; hanya efek lifecycle yang perlu diingat.
        IF usage_record_id IS NULL THEN
            RETURN NEW;
        END IF;

        effect_key := 'ledger:' || NEW.event_type || ':' || usage_record_id;
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
    future_date_count integer;
    reverse_date_count integer;
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
        MAX(step_order),
        COUNT(*) FILTER (
            WHERE acted_on > (CURRENT_TIMESTAMP AT TIME ZONE 'Asia/Makassar')::date
        )
    INTO
        total_steps,
        verifier_count,
        kepala_bagian_count,
        pybmc_count,
        kepala_bagian_order,
        pybmc_order,
        distinct_orders,
        minimum_order,
        maximum_order,
        future_date_count
    FROM leave_usage_external_approval_steps
    WHERE leave_usage_record_id = record_id;

    SELECT COUNT(*) INTO invalid_verifier_count
    FROM leave_usage_external_approval_steps
    WHERE leave_usage_record_id = record_id
      AND step_type = 'verifier'
      AND step_order >= kepala_bagian_order;

    SELECT COUNT(*) INTO reverse_date_count
    FROM (
        SELECT
            acted_on,
            LAG(acted_on) OVER (ORDER BY step_order) AS previous_acted_on
        FROM leave_usage_external_approval_steps
        WHERE leave_usage_record_id = record_id
    ) AS ordered_steps
    WHERE previous_acted_on IS NOT NULL
      AND acted_on < previous_acted_on;

    IF future_date_count > 0 THEN
        RAISE EXCEPTION 'leave_usage_external_approval tanggal tindakan tidak boleh berada di masa depan WITA';
    END IF;

    IF reverse_date_count > 0 THEN
        RAISE EXCEPTION 'leave_usage_external_approval tanggal tindakan wajib kronologis nonmenurun';
    END IF;

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
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
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
SQL);
    }
};
