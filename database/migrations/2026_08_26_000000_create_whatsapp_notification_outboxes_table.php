<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_notification_outboxes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('delivery_id')
                ->unique()
                ->constrained('whatsapp_notification_deliveries')
                ->cascadeOnDelete();
            $table->text('encrypted_payload');
            $table->unsignedTinyInteger('publish_attempts')->default(0);
            $table->timestamp('publish_attempted_at')->nullable();
            $table->timestamp('publish_lease_expires_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_notification_outboxes');
    }
};
