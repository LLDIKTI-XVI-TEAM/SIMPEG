<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('ews_alerts')
            ->where('followup_status', 'kedaluwarsa')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('notifications')
                    ->where('is_read', false)
                    ->where(function ($query): void {
                        $query->whereColumn('notifications.ews_alert_id', 'ews_alerts.id')
                            ->orWhereRaw($this->legacyAlertIdSql());
                    });
            })
            ->update([
                'followup_status' => 'aktif',
                'is_processed' => false,
                'notification_acknowledged_at' => null,
                'handled_at' => null,
                'handled_by' => null,
                'handled_note' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Reversal is intentionally omitted: only legacy expired alerts with an
        // unread reminder are repaired and their prior status is not inferable.
    }

    private function legacyAlertIdSql(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "notifications.data->>'ews_alert_id' = ews_alerts.id::text",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(notifications.data, '$.ews_alert_id')) = ews_alerts.id",
            'sqlite' => "json_extract(notifications.data, '$.ews_alert_id') = ews_alerts.id",
            default => '1 = 0',
        };
    }
};
