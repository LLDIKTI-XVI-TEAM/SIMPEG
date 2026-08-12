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
        // Temukan semua email_pribadi yang memiliki duplikat secara case-insensitive
        $duplicates = DB::table('employees')
            ->whereNotNull('email_pribadi')
            ->selectRaw('LOWER(email_pribadi) as lower_email, COUNT(*) as count')
            ->groupByRaw('LOWER(email_pribadi)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            // Ambil semua pegawai dengan email ini, urutkan dari yang terbaru diubah
            $employees = DB::table('employees')
                ->whereRaw('LOWER(email_pribadi) = ?', [$duplicate->lower_email])
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();

            // Biarkan baris pertama (terbaru) utuh, update sisanya
            $employees->shift();

            foreach ($employees as $emp) {
                // Tambahkan suffix +duplikat{id} sebelum '@' jika ada, atau di akhir string
                $email = $emp->email_pribadi;
                $suffix = '+duplikat'.$emp->id;

                if (str_contains($email, '@')) {
                    $newEmail = str_replace('@', $suffix.'@', $email);
                } else {
                    $newEmail = $email.$suffix;
                }

                DB::table('employees')
                    ->where('id', $emp->id)
                    ->update(['email_pribadi' => $newEmail]);
            }
        }

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
