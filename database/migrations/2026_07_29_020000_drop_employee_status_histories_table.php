<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fitur Status Pegawai bersifat replace/timpa: hanya snapshot status terkini pada
     * employees yang relevan, tidak ada riwayat historis yang ditampilkan atau dipakai.
     * Tabel riwayat ini dihapus agar sejalan dengan keputusan produk tersebut.
     */
    public function up(): void
    {
        Schema::dropIfExists('employee_status_histories');
    }

    public function down(): void
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
            $table->string('berkas_path')->nullable();
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('employee_id');
        });
    }
};
