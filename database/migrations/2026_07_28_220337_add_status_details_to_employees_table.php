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
        Schema::table('employees', function (Blueprint $table) {
            // Ubah tipe data enum menjadi string karena opsi status bertambah
            $table->string('status_aktif', 50)->default('Aktif')->change();
            
            // Kolom baru untuk detail status
            $table->string('status_alasan')->nullable();
            $table->text('status_deskripsi')->nullable();
            $table->date('status_tanggal')->nullable();
            $table->string('status_berkas_path')->nullable();
            $table->string('status_nomor_berkas')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Note: Reverting to enum requires dropping constraints or re-casting in PostgreSQL,
            // so we just drop the new columns in down().
            // We'll leave status_aktif as string since reverting enum safely is complex.
            $table->dropColumn([
                'status_alasan',
                'status_deskripsi',
                'status_tanggal',
                'status_berkas_path',
                'status_nomor_berkas',
            ]);
        });
    }
};

