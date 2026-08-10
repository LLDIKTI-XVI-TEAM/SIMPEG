<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('employee_id')->nullable()->unique()->after('keycloak_username')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite memiliki keterbatasan dalam drop constrained foreign key dengan unique index
            // Migration down tidak digunakan di production
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('employee_id');
        });
    }
};
