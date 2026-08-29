<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_notification_outboxes', function (Blueprint $table): void {
            $table->timestamp('publish_failed_at')->nullable();
            $table->string('publish_failure_code', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_notification_outboxes', function (Blueprint $table): void {
            $table->dropColumn(['publish_failed_at', 'publish_failure_code']);
        });
    }
};
