<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_recovery_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('idempotency_key', 64)->unique();
            $table->string('operation', 32);
            $table->string('status', 32)->default('pending');
            $table->string('category', 64);
            $table->string('disk', 40);
            $table->string('path', 1024);
            $table->uuid('owner_id')->nullable();
            $table->string('source_disk', 40)->nullable();
            $table->string('source_path', 1024)->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'operation', 'id'], 'storage_recovery_tasks_retry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_recovery_tasks');
    }
};
