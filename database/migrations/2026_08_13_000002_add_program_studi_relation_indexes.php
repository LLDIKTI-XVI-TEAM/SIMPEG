<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->index('program_studi_id', 'employees_program_studi_id_index');
        });

        Schema::table('education_histories', function (Blueprint $table): void {
            $table->index('program_studi_id', 'education_histories_program_studi_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('education_histories', function (Blueprint $table): void {
            $table->dropIndex('education_histories_program_studi_id_index');
        });

        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex('employees_program_studi_id_index');
        });
    }
};
