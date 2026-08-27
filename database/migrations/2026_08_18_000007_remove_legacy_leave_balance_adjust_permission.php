<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const WRITER_CONSTRAINT = 'leave_balance_ledger_active_event_type_write_check';

    /** @var list<string> */
    private array $activeLedgerEvents = [
        'annual_entitlement_granted',
        'balance_recalculated',
        'carry_over_expired',
        'carry_over_granted',
        'duty_postponement_recorded',
        'rollover_applied',
        'usage_fact_cancelled',
        'usage_fact_recorded',
        'usage_fact_superseded',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->where('name', 'cuti.balance.adjust')->first();

            if ($permission === null) {
                return;
            }

            // Pivot dicabut sebelum permission agar tidak ada role yang tetap membawa hak direct-write lama.
            DB::table('role_permissions')->where('permission_id', $permission->id)->delete();
            $permission->delete();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->replaceWriterConstraint();
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::query()->updateOrCreate(
                ['name' => 'cuti.balance.adjust'],
                [
                    'module' => 'cuti',
                    'description' => 'Melakukan koreksi saldo cuti yang diaudit',
                ],
            );
            $admin = Role::query()->where('name', 'admin_kepegawaian')->first();

            if ($admin !== null) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $admin->id, 'permission_id' => $permission->id],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE leave_balance_ledger DROP CONSTRAINT IF EXISTS '.self::WRITER_CONSTRAINT);
        }
    }

    private function replaceWriterConstraint(): void
    {
        $events = $this->activeLedgerEvents;
        sort($events);
        $quoted = collect($events)->map(fn (string $event): string => "'{$event}'")->implode(', ');

        // CHECK historis tetap mengenali row legacy; NOT VALID hanya melewati scan awal dan tetap menolak write baru.
        DB::statement('ALTER TABLE leave_balance_ledger DROP CONSTRAINT IF EXISTS '.self::WRITER_CONSTRAINT);
        DB::statement('ALTER TABLE leave_balance_ledger ADD CONSTRAINT '.self::WRITER_CONSTRAINT
            ." CHECK (event_type IN ({$quoted})) NOT VALID");
    }
};
