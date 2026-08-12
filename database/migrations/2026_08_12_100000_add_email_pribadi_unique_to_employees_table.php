<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
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
        DB::transaction(function (): void {
            if (DB::connection()->getDriverName() === 'pgsql') {
                // SHARE memblokir INSERT/UPDATE/DELETE sampai pemeriksaan dan DDL selesai.
                DB::statement('LOCK TABLE employees IN SHARE MODE');
            }

            // Identitas ambigu harus dibersihkan oleh operator; migrasi tidak boleh menebak pemilik email.
            $duplicates = DB::table('employees')
                ->whereNotNull('email_pribadi')
                ->selectRaw('LOWER(email_pribadi) as lower_email, COUNT(*) as duplicate_count')
                ->groupByRaw('LOWER(email_pribadi)')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            if ($duplicates->isNotEmpty()) {
                $affectedEmployees = $duplicates->sum(
                    static fn (object $duplicate): int => (int) $duplicate->duplicate_count,
                );

                throw new RuntimeException(
                    "Ditemukan {$duplicates->count()} kelompok email_pribadi duplikat yang mencakup "
                    ."{$affectedEmployees} pegawai. Bersihkan duplikat secara eksplisit sebelum menjalankan migrasi kembali.",
                );
            }

            try {
                DB::statement(
                    'CREATE UNIQUE INDEX employees_email_pribadi_unique '
                    .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
                );
            } catch (QueryException $exception) {
                $this->rethrowIndexCreationFailure($exception);
            }
        });
    }

    /** Putuskan exception chain hanya untuk pelanggaran unique yang dapat memuat email pegawai. */
    private function rethrowIndexCreationFailure(QueryException $exception): never
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getPrevious()?->getCode() ?? '');

        if ($sqlState !== '23505') {
            throw $exception;
        }

        throw new RuntimeException(
            'Indeks email_pribadi tidak dapat dibuat karena masih ada identitas duplikat. '
            .'Bersihkan duplikat secara eksplisit sebelum menjalankan migrasi kembali.',
        );
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex('employees_email_pribadi_unique');
        });
    }
};
