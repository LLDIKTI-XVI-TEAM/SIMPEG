<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ews_alerts', function (Blueprint $table): void {
            // Hasil evaluasi eligibility yang disimpan saat alert dibuat.
            // null  = tipe yang tidak memiliki eligibility check (KGB, Pensiun, Kontrak PPPK)
            // true  = eligible (notifikasi dikirim)
            // false = tidak eligible (alert dibuat tapi notifikasi tidak dikirim)
            $table->boolean('is_eligible')->nullable()->after('notified_at');

            // Milestone Satyalancana dalam tahun (10, 20, atau 30).
            // null untuk semua tipe selain SATYALANCANA.
            $table->unsignedTinyInteger('satyalancana_years')->nullable()->after('is_eligible');
        });
    }

    public function down(): void
    {
        Schema::table('ews_alerts', function (Blueprint $table): void {
            $table->dropColumn(['is_eligible', 'satyalancana_years']);
        });
    }
};
