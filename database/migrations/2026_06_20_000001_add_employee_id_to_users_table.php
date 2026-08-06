<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::table('users', function (Blueprint $table): void {
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropConstrainedForeignId('employee_id');
            } else {
                $table->dropColumn('employee_id');
            }
        });
    }
};
