<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var list<string> */
    private array $auditEvents = [
        'CREATE', 'UPDATE', 'DELETE', 'SOFT_DELETE', 'RESTORE', 'LOGIN', 'LOGOUT', 'APPROVE', 'POSTPONE', 'IMPORT', 'SESSION_TIMEOUT',
        'LEAVE_BALANCE_OPENING_SET', 'LEAVE_BALANCE_CORRECTED', 'LEAVE_ROLLOVER_APPLIED', 'LEAVE_BALANCE_DEDUCTED', 'LEAVE_PROOF_GENERATED',
        'CONFIG_UPDATE', 'LEAVE_BALANCE_RESERVED', 'LEAVE_BALANCE_RESERVATION_ADJUSTED', 'LEAVE_BALANCE_RESERVATION_CONVERTED',
        'LEAVE_BALANCE_RESERVATION_RELEASED', 'DUTY_POSTPONEMENT', 'VERIFY', 'DECIDE', 'CHANGE_REQUESTED', 'DEFER', 'NOT_APPROVED',
        'SWITCH_ROLE', 'REVERT_ROLE', 'ROLE_SIMULATION_USAGE',
        'SSO_BINDING', 'SSO_MAPPING_REJECTED',
        'LEAVE_CANCELLATION_REQUESTED', 'LEAVE_CANCELLATION_APPROVED', 'LEAVE_CANCELLATION_REJECTED',
    ];

    public function up(): void
    {
        $this->applyAuditConstraint($this->auditEvents);
    }

    public function down(): void
    {
        if (DB::table('audit_logs')->whereIn('event', ['SSO_BINDING', 'SSO_MAPPING_REJECTED'])->exists()) {
            throw new RuntimeException(
                'Rollback dibatalkan karena audit_logs berisi event SSO_BINDING/SSO_MAPPING_REJECTED.'
            );
        }

        $this->applyAuditConstraint(array_values(array_diff(
            $this->auditEvents,
            ['SSO_BINDING', 'SSO_MAPPING_REJECTED'],
        )));
    }

    /** @param list<string> $events */
    private function applyAuditConstraint(array $events): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $quotedEvents = collect($events)->map(fn (string $event): string => "'{$event}'")->implode(', ');
        DB::statement('ALTER TABLE audit_logs DROP CONSTRAINT IF EXISTS audit_logs_event_check');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check CHECK (event IN ({$quotedEvents}))");
    }
};
