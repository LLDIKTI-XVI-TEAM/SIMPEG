<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->timestamp('notification_acknowledged_at')->nullable()->after('notified_at');
        });

        Schema::table('notifications', function (Blueprint $table): void {
            $table->foreignUuid('ews_alert_id')->nullable()->after('user_id')->constrained('ews_alerts')->nullOnDelete();
            $table->unique(['user_id', 'ews_alert_id'], 'notifications_user_ews_alert_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropUnique('notifications_user_ews_alert_unique');
            $table->dropConstrainedForeignId('ews_alert_id');
        });

        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->dropColumn('notification_acknowledged_at');
        });
    }
};
