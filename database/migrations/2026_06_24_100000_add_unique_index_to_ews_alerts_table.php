<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ews_alerts', function (Blueprint $table) {
            $table->unique(['employee_id', 'type', 'target_date', 'interval_days'], 'ews_alerts_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ews_alerts', function (Blueprint $table) {
            $table->dropUnique('ews_alerts_unique');
        });
    }
};
