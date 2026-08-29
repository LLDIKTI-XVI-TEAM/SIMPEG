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
            // Marker commit bersama record notifikasi agar retry hanya memulihkan
            // intent yang terputus setelah mutasi domain berhasil.
            $table->timestamp('lifecycle_notified_at')->nullable()->after('notification_acknowledged_at');
            $table->timestamp('followup_notified_at')->nullable()->after('lifecycle_notified_at');
        });

        // Alert terminal historis telah melewati jalur delivery lama dan tidak boleh
        // dibuka ulang sebagai recovery hanya karena marker baru awalnya nullable.
        $processedAt = DB::raw('COALESCE(handled_at, updated_at, created_at, CURRENT_TIMESTAMP)');
        DB::table('ews_alerts')
            ->whereIn('followup_status', ['ditangani', 'tidak_perlu', 'kedaluwarsa'])
            ->update(['followup_notified_at' => $processedAt]);
        DB::table('ews_alerts')
            ->where('type', 'PENSIUN')
            ->where('followup_status', 'ditangani')
            ->update(['lifecycle_notified_at' => $processedAt]);
    }

    public function down(): void
    {
        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->dropColumn(['lifecycle_notified_at', 'followup_notified_at']);
        });
    }
};
