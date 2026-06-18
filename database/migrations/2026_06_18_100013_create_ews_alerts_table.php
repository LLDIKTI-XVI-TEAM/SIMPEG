<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ews_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->enum('type', [
                'KENAIKAN_PANGKAT',
                'KGB',
                'PENSIUN',
                'KONTRAK_PPPK',
            ]);
            $table->date('target_date');
            $table->integer('interval_days');
            $table->timestamp('notified_at')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamps();

            $table->index('employee_id');
            $table->index('type');
            $table->index('is_processed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ews_alerts');
    }
};
