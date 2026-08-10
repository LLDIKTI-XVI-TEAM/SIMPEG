<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Membuat penyimpanan milestone terhitung agar scheduler tidak menghitung ulang setiap hari. */
    public function up(): void
    {
        Schema::create('employee_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('type', 50);
            $table->date('milestone_date');
            $table->date('calculated_at');
            $table->json('metadata')->nullable();
            // Milestone lama dinonaktifkan agar jejak perhitungan tetap dapat diaudit.
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['employee_id', 'type']);
            $table->index(['milestone_date', 'is_active']);
            $table->index('type');
        });
    }

    /** Menghapus tabel milestone ketika migration ini dibatalkan. */
    public function down(): void
    {
        Schema::dropIfExists('employee_milestones');
    }
};
