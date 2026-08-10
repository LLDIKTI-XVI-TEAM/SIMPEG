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
            $table->string('keycloak_username')->nullable()->unique()->after('keycloak_id');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite memiliki keterbatasan dalam drop column dengan unique index
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('keycloak_username');
        });
    }
};
