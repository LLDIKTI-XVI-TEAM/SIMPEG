<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom nama_dengan_gelar ke tabel employees.
 *
 * Konteks:
 * - nama_lengkap  : nama lengkap tanpa gelar (contoh: Grantly Antonio Edward Sorongan)
 * - nama_dengan_gelar : nama yang ditampilkan sehari-hari, sudah termasuk gelar akademik
 *                       (contoh: Grantly Sorongan, S.Kom.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            // Ditempatkan setelah nama_lengkap agar urutan kolom logis
            $table->string('nama_dengan_gelar', 255)
                ->nullable()
                ->after('nama_lengkap')
                ->comment('Nama beserta gelar akademik, contoh: Grantly Sorongan, S.Kom.');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('nama_dengan_gelar');
        });
    }
};
