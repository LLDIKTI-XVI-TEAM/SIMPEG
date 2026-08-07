<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 50); // 'kenaikan_pangkat', 'kgb', 'pensiun', 'satyalancana', 'pppk_contract_end'
            $table->date('milestone_date'); // Tanggal event
            $table->date('calculated_at'); // Kapan dihitung (untuk tracking staleness)
            $table->json('metadata')->nullable(); // Data pendukung (TMT asal, golongan, years of service, dll)
            $table->boolean('is_active')->default(true); // Untuk soft invalidation tanpa hapus record
            $table->timestamps();

            $table->index(['employee_id', 'type']);
            $table->index(['milestone_date', 'is_active']);
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_milestones');
    }
};
