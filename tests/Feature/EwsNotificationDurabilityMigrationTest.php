<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EwsAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EwsNotificationDurabilityMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_backfills_existing_terminal_alerts_as_non_recoverable(): void
    {
        $employee = Employee::factory()->create();
        $handledAt = now()->subHour()->startOfSecond();
        $historical = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'target_date' => now()->subDay()->toDateString(),
            'interval_days' => 90,
            'is_processed' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'handled_at' => $handledAt,
        ]);
        $active = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'target_date' => now()->addDay()->toDateString(),
            'interval_days' => 180,
            'is_processed' => false,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $migration = require database_path('migrations/2026_08_28_000001_add_followup_notification_durability_to_ews_alerts.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('ews_alerts', 'lifecycle_notified_at'));
        $this->assertFalse(Schema::hasColumn('ews_alerts', 'followup_notified_at'));

        $migration->up();

        $historical->refresh();
        $active->refresh();
        $this->assertSame($handledAt->toDateTimeString(), $historical->lifecycle_notified_at?->toDateTimeString());
        $this->assertSame($handledAt->toDateTimeString(), $historical->followup_notified_at?->toDateTimeString());
        $this->assertNull($active->lifecycle_notified_at);
        $this->assertNull($active->followup_notified_at);
    }

    public function test_rollback_superseded_marker_tetap_terminal_bagi_versi_lama(): void
    {
        $employee = Employee::factory()->create();
        $supersededAt = now()->subMinute()->startOfSecond();
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'PENSIUN',
            'target_date' => now()->toDateString(),
            'interval_days' => 90,
            'is_processed' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
            'lifecycle_notification_superseded_at' => $supersededAt,
        ]);
        $migration = require database_path('migrations/2026_08_28_000003_add_lifecycle_notification_superseded_to_ews_alerts.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('ews_alerts', 'lifecycle_notification_superseded_at'));
        $this->assertFalse(Schema::hasColumn('ews_alerts', 'lifecycle_status_history_id'));
        $this->assertSame(
            $supersededAt->toDateTimeString(),
            Carbon::parse((string) DB::table('ews_alerts')->where('id', $alert->id)->value('lifecycle_notified_at'))->toDateTimeString(),
        );

        $migration->up();
        $alert->refresh();
        $this->assertSame($supersededAt->toDateTimeString(), $alert->lifecycle_notified_at?->toDateTimeString());
        $this->assertNull($alert->lifecycle_notification_superseded_at);
    }
}
