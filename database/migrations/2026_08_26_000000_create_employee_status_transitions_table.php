<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K-STATUS-06: perubahan status dengan tanggal efektif di masa depan disimpan
     * sebagai transisi terjadwal; snapshot/akses tidak berubah sampai jatuh tempo,
     * lalu scheduler (Asia/Makassar) menerapkannya secara idempoten.
     */
    public function up(): void
    {
        Schema::create('employee_status_transitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUuid('status_pegawai_id')->constrained('ref_status_pegawai');
            $table->date('tanggal_efektif');
            $table->string('kind', 20)->default('status'); // deactivate | restore | status
            $table->text('keterangan')->nullable();
            $table->text('status_note')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_applied')->default(false);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['is_applied', 'tanggal_efektif']);
            $table->index('employee_id');
            // Idempotensi: transisi terjadwal yang sama persis tidak boleh disimpan dua kali.
            $table->unique(['employee_id', 'status_pegawai_id', 'tanggal_efektif']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_status_transitions');
    }
};
