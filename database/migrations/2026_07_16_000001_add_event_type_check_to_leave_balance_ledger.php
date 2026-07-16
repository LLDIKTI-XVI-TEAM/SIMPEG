<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Snapshot event resmi ledger. Migration tidak mengambil konstanta aplikasi agar histori schema tetap stabil.
     *
     * @var list<string>
     */
    private array $allowedEventTypes = [
        'annual_entitlement_granted',
        'carry_over_expired',
        'carry_over_granted',
        'duty_postponement_recorded',
        'leave_deducted',
        'manual_adjustment',
        'opening_balance_set',
        'rollover_applied',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $invalidEventTypes = DB::table('leave_balance_ledger')
            ->whereNotIn('event_type', $this->allowedEventTypes)
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->all();

        if ($invalidEventTypes !== []) {
            throw new RuntimeException(
                'Constraint event ledger cuti tidak dapat dipasang karena terdapat event tidak dikenal: '
                .implode(', ', $invalidEventTypes)
            );
        }

        $quotedEventTypes = collect($this->allowedEventTypes)
            ->map(fn (string $eventType): string => "'{$eventType}'")
            ->implode(', ');

        DB::statement('ALTER TABLE leave_balance_ledger DROP CONSTRAINT IF EXISTS leave_balance_ledger_event_type_check');
        DB::statement("ALTER TABLE leave_balance_ledger ADD CONSTRAINT leave_balance_ledger_event_type_check CHECK (event_type IN ({$quotedEventTypes}))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_balance_ledger DROP CONSTRAINT IF EXISTS leave_balance_ledger_event_type_check');
        }
    }
};
