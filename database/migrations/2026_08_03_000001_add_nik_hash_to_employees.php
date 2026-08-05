<?php

use App\Models\Employee;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        Employee::withTrashed()
            ->whereNotNull('nik')
            ->chunkById(200, function ($employees) use ($appKey): void {
                foreach ($employees as $employee) {
                    /** @var Employee $employee */
                    try {
                        $plainNik = $employee->nik; // didekripsi oleh 'encrypted' cast
                    } catch (DecryptException $e) {
                        Log::warning("[Migration] Gagal mendekripsi NIK untuk pegawai ID: {$employee->id} karena APP_KEY berubah. Melewati hash.");

                        continue;
                    }

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

        // Fase 2.5: Rekonsiliasi duplikat nik_hash sebelum menambah unique constraint.
        // Duplikat bisa terjadi jika dua pegawai (aktif atau soft-deleted) memiliki NIK yang sama,
        // karena validasi keunikan belum ada sebelum PR ini. Untuk setiap kelompok duplikat:
        // pertahankan satu record (aktif + terbaru) dan nullify nik_hash sisanya.
        // NIK terenkripsi tidak diubah — hanya blind index yang dikosongkan agar tidak memblokir constraint.
        // Setiap record yang di-nullify dicatat ke log untuk rekonsiliasi manual oleh tim.
        $duplicateHashes = DB::table('employees')
            ->select('nik_hash')
            ->whereNotNull('nik_hash')
            ->groupBy('nik_hash')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('nik_hash');

        foreach ($duplicateHashes as $hash) {
            $rows = DB::table('employees')
                ->where('nik_hash', $hash)
                ->orderByRaw('deleted_at IS NOT NULL ASC') // utamakan record aktif (deleted_at NULL)
                ->orderBy('created_at', 'desc')            // di antara yang setara, pilih yang terbaru
                ->get(['id', 'deleted_at', 'created_at']);

            // Baris pertama adalah "pemenang" — nik_hash-nya dipertahankan.
            // Semua baris sisanya di-nullify agar tidak melanggar unique constraint.
            $losers = $rows->slice(1);

            foreach ($losers as $loser) {
                DB::table('employees')
                    ->where('id', $loser->id)
                    ->update(['nik_hash' => null]);

                Log::warning(
                    '[Migration] Duplikat NIK ditemukan: nik_hash dinullifikasi untuk rekonsiliasi. Lakukan verifikasi manual pada record ini.',
                    [
                        'employee_id' => $loser->id,
                        'nik_hash' => $hash,
                        'deleted_at' => $loser->deleted_at,
                    ]
                );
            }
        }

        // Fase 3: Tambah unique index SETELAH backfill dan rekonsiliasi duplikat selesai.
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
