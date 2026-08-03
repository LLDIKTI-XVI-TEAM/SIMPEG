<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fase 1: Tambah kolom nullable dulu (belum ada unique constraint)
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('nik_hash', 64)
                ->nullable()
                ->after('nik')
                ->comment('HMAC-SHA256 blind index of NIK for uniqueness checks. Must be recomputed after APP_KEY rotation.');
        });

        // Fase 2: Backfill semua baris termasuk soft-deleted.
        // 'encrypted' cast mendekripsi saat read — harus iterasi di PHP, tidak bisa pakai SQL langsung.
        $appKey = config('app.key');

        \App\Models\Employee::withTrashed()
            ->whereNotNull('nik')
            ->chunkById(200, function ($employees) use ($appKey): void {
                foreach ($employees as $employee) {
                    /** @var \App\Models\Employee $employee */
                    $plainNik = $employee->nik; // didekripsi oleh 'encrypted' cast

                    if ($plainNik === null || trim((string) $plainNik) === '') {
                        continue;
                    }

                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'nik_hash' => hash_hmac('sha256', trim((string) $plainNik), $appKey),
                        ]);
                }
            });

        // Fase 3: Tambah unique index SETELAH backfill selesai.
        // MySQL/MariaDB mengizinkan banyak NULL dalam unique index — sesuai kebutuhan kolom nullable.
        Schema::table('employees', function (Blueprint $table): void {
            $table->unique('nik_hash', 'employees_nik_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_nik_hash_unique');
            $table->dropColumn('nik_hash');
        });
    }
};
