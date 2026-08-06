<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['keycloak_username']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('keycloak_username');
        });
    }
};
