<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Menyimpan marker publish dan lease recovery tanpa mengubah payload eksekusi import. */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->timestamp('job_publish_attempted_at')->nullable();
            $table->timestamp('job_published_at')->nullable();
            $table->timestamp('job_publish_lease_expires_at')->nullable();
            $table->unsignedInteger('job_publish_attempts')->default(0);

            $table->index(
                ['status', 'job_published_at', 'started_at', 'job_publish_lease_expires_at'],
                'import_batches_publish_recovery_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table): void {
            $table->dropIndex('import_batches_publish_recovery_index');
            $table->dropColumn([
                'job_publish_attempted_at',
                'job_published_at',
                'job_publish_lease_expires_at',
                'job_publish_attempts',
            ]);
        });
    }
};
