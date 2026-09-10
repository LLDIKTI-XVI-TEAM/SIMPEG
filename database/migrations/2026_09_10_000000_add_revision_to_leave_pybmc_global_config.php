<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kunci sebelum preflight agar writer tidak menambah timestamp kembar di sela perubahan schema.
        DB::statement('LOCK TABLE leave_pybmc_global_config IN ACCESS EXCLUSIVE MODE');
        $latest = DB::table('leave_pybmc_global_config')
            ->orderByDesc('effective_from')->orderByDesc('created_at')
            ->limit(2)->get(['effective_from', 'created_at']);

        if ($latest->count() === 2
            && $latest[0]->effective_from === $latest[1]->effective_from
            && $latest[0]->created_at === $latest[1]->created_at) {
            throw new RuntimeException(
                'Urutan konfigurasi PYBMC global terbaru ambigu. Verifikasi konfigurasi yang benar bersama pengelola data sebelum melanjutkan; migrasi tidak mengubah histori.',
            );
        }

        Schema::table('leave_pybmc_global_config', function (Blueprint $table): void {
            // Nol menandai histori sebelum penomoran; timestamp dan audit aslinya tidak ditulis ulang.
            $table->bigInteger('revision')->default(0);
        });
        DB::statement('CREATE UNIQUE INDEX leave_pybmc_revision_unique ON leave_pybmc_global_config (revision) WHERE revision > 0');
    }

    public function down(): void
    {
        // Revisi yang telah dipakai tidak boleh dibuang karena waktu yang sama tidak menyimpan urutan writer.
        DB::statement('LOCK TABLE leave_pybmc_global_config IN ACCESS EXCLUSIVE MODE');
        if (DB::table('leave_pybmc_global_config')->where('revision', '>', 0)->exists()) {
            throw new RuntimeException('Rollback dibatalkan karena revisi PYBMC global sudah dipakai.');
        }

        Schema::table('leave_pybmc_global_config', function (Blueprint $table): void {
            $table->dropIndex('leave_pybmc_revision_unique');
            $table->dropColumn('revision');
        });
    }
};
