<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ews_alerts', function (Blueprint $table): void {
            // Marker terpisah menjaga lifecycle_notified_at hanya berarti pesan benar-benar terkirim.
            $table->timestamp('lifecycle_notification_superseded_at')
                ->nullable()
                ->after('lifecycle_notified_at');
            $table->uuid('lifecycle_status_history_id')
                ->nullable()
                ->after('lifecycle_notification_superseded_at');
        });
    }

    public function down(): void
    {
        // Versi lama hanya mengenal marker "terkirim" sebagai terminal. Pemetaan ini
        // mencegah rollback menghidupkan kembali intent basi yang sudah dibatalkan.
        DB::table('ews_alerts')
            ->whereNull('lifecycle_notified_at')
            ->whereNotNull('lifecycle_notification_superseded_at')
            ->update(['lifecycle_notified_at' => DB::raw('lifecycle_notification_superseded_at')]);

        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->dropColumn([
                'lifecycle_notification_superseded_at',
                'lifecycle_status_history_id',
            ]);
        });
    }
};
