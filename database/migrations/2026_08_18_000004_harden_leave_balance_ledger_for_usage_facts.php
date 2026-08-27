<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $eventTypes = [
        'annual_entitlement_granted',
        'balance_recalculated',
        'carry_over_expired',
        'carry_over_granted',
        'duty_postponement_recorded',
        'leave_deducted',
        'manual_adjustment',
        'opening_balance_set',
        'rollover_applied',
        'usage_fact_cancelled',
        'usage_fact_recorded',
        'usage_fact_superseded',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $quoted = collect($this->eventTypes)->map(fn (string $event): string => "'{$event}'")->implode(', ');
        DB::statement('ALTER TABLE leave_balance_ledger DROP CONSTRAINT IF EXISTS leave_balance_ledger_event_type_check');
        DB::statement("ALTER TABLE leave_balance_ledger ADD CONSTRAINT leave_balance_ledger_event_type_check CHECK (event_type IN ({$quoted}))");

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_leave_balance_ledger_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'ledger saldo cuti bersifat append-only';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER leave_balance_ledger_no_update_delete
BEFORE UPDATE OR DELETE ON leave_balance_ledger
FOR EACH ROW EXECUTE FUNCTION guard_leave_balance_ledger_append_only();

CREATE TRIGGER leave_balance_ledger_no_truncate
BEFORE TRUNCATE ON leave_balance_ledger
FOR EACH STATEMENT EXECUTE FUNCTION guard_leave_balance_ledger_append_only();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::table('leave_balance_ledger')->whereIn('event_type', [
            'usage_fact_recorded',
            'usage_fact_superseded',
            'usage_fact_cancelled',
            'balance_recalculated',
        ])->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena ledger sudah berisi event fakta pemakaian.');
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS leave_balance_ledger_no_update_delete ON leave_balance_ledger;
DROP TRIGGER IF EXISTS leave_balance_ledger_no_truncate ON leave_balance_ledger;
DROP FUNCTION IF EXISTS guard_leave_balance_ledger_append_only();
SQL);

        $legacyEvents = array_values(array_diff($this->eventTypes, [
            'balance_recalculated',
            'usage_fact_cancelled',
            'usage_fact_recorded',
            'usage_fact_superseded',
        ]));
        $quoted = collect($legacyEvents)->map(fn (string $event): string => "'{$event}'")->implode(', ');
        DB::statement('ALTER TABLE leave_balance_ledger DROP CONSTRAINT IF EXISTS leave_balance_ledger_event_type_check');
        DB::statement("ALTER TABLE leave_balance_ledger ADD CONSTRAINT leave_balance_ledger_event_type_check CHECK (event_type IN ({$quoted}))");
    }
};
