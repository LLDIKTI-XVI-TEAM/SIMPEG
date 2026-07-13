<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan index pada kolom yang sering digunakan untuk pencarian dan filter
 * di halaman data pegawai, dokumen SK, dan backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── employees ─────────────────────────────────────────────────────────
        Schema::table('employees', function (Blueprint $table): void {
            // Pencarian nama/NIP (LOWER() LIKE) — partial index tidak ada di semua DB,
            // index biasa tetap membantu optimizer.
            if (! $this->indexExists('employees', 'employees_nama_lengkap_idx')) {
                $table->index('nama_lengkap', 'employees_nama_lengkap_idx');
            }
            if (! $this->indexExists('employees', 'employees_nip_idx')) {
                $table->index('nip', 'employees_nip_idx');
            }
            // Filter jenis_pegawai + status
            if (! $this->indexExists('employees', 'employees_jenis_pegawai_id_idx')) {
                $table->index('jenis_pegawai_id', 'employees_jenis_pegawai_id_idx');
            }
            if (! $this->indexExists('employees', 'employees_status_pegawai_id_idx')) {
                $table->index('status_pegawai_id', 'employees_status_pegawai_id_idx');
            }
            // Backup — filter soft-deleted by date
            if (! $this->indexExists('employees', 'employees_deleted_at_idx')) {
                $table->index('deleted_at', 'employees_deleted_at_idx');
            }
        });

        // ── documents ─────────────────────────────────────────────────────────
        Schema::table('documents', function (Blueprint $table): void {
            // Filter kategori dokumen
            if (! $this->indexExists('documents', 'documents_jenis_dokumen_idx')) {
                $table->index('jenis_dokumen', 'documents_jenis_dokumen_idx');
            }
            // Pencarian nama/nomor dokumen
            if (! $this->indexExists('documents', 'documents_nama_dokumen_idx')) {
                $table->index('nama_dokumen', 'documents_nama_dokumen_idx');
            }
            // Sort by created_at (default order di halaman dokumen)
            if (! $this->indexExists('documents', 'documents_created_at_idx')) {
                $table->index('created_at', 'documents_created_at_idx');
            }
        });

        // ── position_histories ────────────────────────────────────────────────
        Schema::table('position_histories', function (Blueprint $table): void {
            // Composite index untuk query "WHERE employee_id = ? AND is_latest = true"
            if (! $this->indexExists('position_histories', 'ph_employee_latest_idx')) {
                $table->index(['employee_id', 'is_latest'], 'ph_employee_latest_idx');
            }
            // Filter unit_kerja pada position_histories
            if (! $this->indexExists('position_histories', 'ph_unit_kerja_latest_idx')) {
                $table->index(['unit_kerja_id', 'is_latest'], 'ph_unit_kerja_latest_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropIndex('employees_nama_lengkap_idx');
            $table->dropIndex('employees_nip_idx');
            $table->dropIndex('employees_jenis_pegawai_id_idx');
            $table->dropIndex('employees_status_pegawai_id_idx');
            $table->dropIndex('employees_deleted_at_idx');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('documents_jenis_dokumen_idx');
            $table->dropIndex('documents_nama_dokumen_idx');
            $table->dropIndex('documents_created_at_idx');
        });

        Schema::table('position_histories', function (Blueprint $table): void {
            $table->dropIndex('ph_employee_latest_idx');
            $table->dropIndex('ph_unit_kerja_latest_idx');
        });
    }

    /**
     * Cek apakah index sudah ada — agar migration idempotent di environment yang mungkin
     * sudah punya sebagian index dari migration lain.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = \Illuminate\Support\Facades\DB::select(
            "SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname = ?",
            [$table, $indexName]
        );

        return count($indexes) > 0;
    }
};
