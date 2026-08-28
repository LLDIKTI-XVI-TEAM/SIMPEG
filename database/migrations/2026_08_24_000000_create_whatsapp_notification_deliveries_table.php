<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('idempotency_key', 64)->unique();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('event_key', 100);
            $table->string('template_key', 100);
            $table->string('status', 20)->index();
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->string('failure_code', 40)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_deliveries');
    }
};
