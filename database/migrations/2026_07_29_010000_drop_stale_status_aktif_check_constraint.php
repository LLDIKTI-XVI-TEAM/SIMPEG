<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migration 2026_06_18_100002 membuat employees.status_aktif sebagai enum Postgres
     * (Aktif, Non-Aktif, Pensiun, Mutasi). Migration 2026_07_28_220337 mengubah kolomnya
     * menjadi string via ->change(), tetapi di PostgreSQL, Schema::change() hanya mengubah
     * tipe kolom dan TIDAK menghapus CHECK constraint lama yang dibuat oleh enum() Laravel.
     * Akibatnya, constraint "employees_status_aktif_check" tetap aktif dan menolak nilai
     * status baru seperti "Pemberhentian Sementara", "Cuti di Luar Tanggungan Negara", dst.
     * yang seharusnya sudah didukung sejak kolom menjadi string bebas.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE employees DROP CONSTRAINT IF EXISTS employees_status_aktif_check');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Constraint lama dibatasi ke 4 nilai enum awal; tidak dipulihkan agar tidak
        // memblokir status lain (Pemberhentian Sementara, CLTN, dll.) yang sudah didukung.
    }
};
