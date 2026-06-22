<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'email_pribadi') && ! Schema::hasColumn('employees', 'email')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->renameColumn('email_pribadi', 'email');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'email') && ! Schema::hasColumn('employees', 'email_pribadi')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->renameColumn('email', 'email_pribadi');
            });
        }
    }
};
