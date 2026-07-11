<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat kolom no_ijazah menjadi nullable.
 * Tidak semua jenjang pendidikan memiliki nomor ijazah resmi (mis. SD, SMP),
 * sehingga kolom ini harus dapat menyimpan nilai NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('education_histories', function (Blueprint $table): void {
            $table->string('no_ijazah', 100)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('education_histories', function (Blueprint $table): void {
            // Isi kolom yang NULL dengan string kosong sebelum menghapus nullable,
            // agar rollback tidak gagal karena data yang sudah ada.
            \Illuminate\Support\Facades\DB::statement(
                "UPDATE education_histories SET no_ijazah = '' WHERE no_ijazah IS NULL"
            );
            $table->string('no_ijazah', 100)->nullable(false)->change();
        });
    }
};
