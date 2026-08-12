<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tegakkan keunikan email_pribadi secara atomik di level database agar race antara validasi
     * dan eksekusi import tidak menghasilkan dua pegawai dengan email yang sama.
     *
     * Indeks dibuat sebagai functional index case-insensitive (PostgreSQL) agar benturan terdeteksi
     * terlepas dari kapitalisasi yang dimasukkan operator. Indeks parsial (WHERE NOT NULL) memastikan
     * baris tanpa email tidak saling memblokir.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX employees_email_pribadi_unique '
            .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
        );
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex('employees_email_pribadi_unique');
        });
    }
};
