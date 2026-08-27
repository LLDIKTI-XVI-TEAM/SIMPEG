<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'storage_recovery_tasks_migration_lookup_index';

    public function up(): void
    {
        if (! Schema::hasIndex('storage_recovery_tasks', self::INDEX)) {
            Schema::table('storage_recovery_tasks', function (Blueprint $table): void {
                // source_path sengaja tidak diindeks karena panjangnya dapat melampaui batas B-tree PostgreSQL.
                $table->index(
                    ['operation', 'status', 'category', 'source_disk', 'sha256', 'owner_id'],
                    self::INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('storage_recovery_tasks', self::INDEX)) {
            Schema::table('storage_recovery_tasks', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
