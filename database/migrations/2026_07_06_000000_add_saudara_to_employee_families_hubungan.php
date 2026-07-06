<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel 10+ mendukung ->change() secara native untuk PostgreSQL dan SQLite
        // tanpa memerlukan raw SQL. Ini secara otomatis menangani perbedaan sintaks
        // CHECK constraint antar database driver.
        Schema::table('employee_families', function (Blueprint $table): void {
            $table->enum('hubungan', ['Suami', 'Istri', 'Anak', 'Saudara'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_families', function (Blueprint $table): void {
            $table->enum('hubungan', ['Suami', 'Istri', 'Anak'])->change();
        });
    }
};
