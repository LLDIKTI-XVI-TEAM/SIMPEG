<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Menyimpan state eksekusi yang diperlukan worker untuk pulih tanpa bergantung pada cache. */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->longText('execution_payload')->nullable();
            $table->unsignedInteger('processed_valid_count')->default(0);
            $table->uuid('processing_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completion_notified_at')->nullable();
            $table->timestamp('failure_notified_at')->nullable();

            $table->index(['status', 'lease_expires_at'], 'import_batches_status_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropIndex('import_batches_status_lease_index');
            $table->dropColumn([
                'execution_payload',
                'processed_valid_count',
                'processing_token',
                'lease_expires_at',
                'completion_notified_at',
                'failure_notified_at',
            ]);
        });
    }
};
