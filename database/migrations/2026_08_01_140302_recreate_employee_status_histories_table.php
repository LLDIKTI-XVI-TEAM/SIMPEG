<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabel riwayat perubahan status kepegawaian - append-only history.
     * Setiap perubahan status membuat record baru dengan is_latest flag.
     * Field file_sk menyimpan path SK per-record, tidak ada deletion.
     */
    public function up(): void
    {
        Schema::create('employee_status_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('status_pegawai_id')->nullable()->constrained('ref_status_pegawai')->nullOnDelete();
            $table->string('status_nama', 100);
            $table->string('alasan', 255);
            $table->text('deskripsi')->nullable();
            $table->date('tanggal_efektif');
            $table->string('nomor_berkas')->nullable();
            $table->string('file_sk')->nullable();
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_latest')->default(false);
            $table->timestamps();

            $table->index('employee_id');
            $table->index(['employee_id', 'is_latest']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_histories');
    }
};
