<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan index pada kolom yang sering digunakan untuk pencarian dan filter
 * di halaman data pegawai, dokumen SK, dan backup.
 *
 * Migration ini database-agnostic (SQLite, PostgreSQL, MySQL).
 * Pengecekan index existing menggunakan Schema::hasIndex() / getIndexes()
 * agar tidak error di SQLite saat test.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── employees ─────────────────────────────────────────────────────────
        Schema::table('employees', function (Blueprint $table): void {
            if (! $this->hasIndex('employees', 'employees_nama_lengkap_idx')) {
                $table->index('nama_lengkap', 'employees_nama_lengkap_idx');
            }
            if (! $this->hasIndex('employees', 'employees_nip_idx')) {
                $table->index('nip', 'employees_nip_idx');
            }
            if (! $this->hasIndex('employees', 'employees_jenis_pegawai_id_idx')) {
                $table->index('jenis_pegawai_id', 'employees_jenis_pegawai_id_idx');
            }
            if (! $this->hasIndex('employees', 'employees_status_pegawai_id_idx')) {
                $table->index('status_pegawai_id', 'employees_status_pegawai_id_idx');
            }
            if (! $this->hasIndex('employees', 'employees_deleted_at_idx')) {
                $table->index('deleted_at', 'employees_deleted_at_idx');
            }
        });

        // ── documents ─────────────────────────────────────────────────────────
        Schema::table('documents', function (Blueprint $table): void {
            if (! $this->hasIndex('documents', 'documents_jenis_dokumen_idx')) {
                $table->index('jenis_dokumen', 'documents_jenis_dokumen_idx');
            }
            if (! $this->hasIndex('documents', 'documents_nama_dokumen_idx')) {
                $table->index('nama_dokumen', 'documents_nama_dokumen_idx');
            }
            if (! $this->hasIndex('documents', 'documents_created_at_idx')) {
                $table->index('created_at', 'documents_created_at_idx');
            }
        });

        // ── position_histories ────────────────────────────────────────────────
        Schema::table('position_histories', function (Blueprint $table): void {
            if (! $this->hasIndex('position_histories', 'ph_employee_latest_idx')) {
                $table->index(['employee_id', 'is_latest'], 'ph_employee_latest_idx');
            }
            if (! $this->hasIndex('position_histories', 'ph_unit_kerja_latest_idx')) {
                $table->index(['unit_kerja_id', 'is_latest'], 'ph_unit_kerja_latest_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            foreach ([
                'employees_nama_lengkap_idx',
                'employees_nip_idx',
                'employees_jenis_pegawai_id_idx',
                'employees_status_pegawai_id_idx',
                'employees_deleted_at_idx',
            ] as $index) {
                if ($this->hasIndex('employees', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        Schema::table('documents', function (Blueprint $table): void {
            foreach ([
                'documents_jenis_dokumen_idx',
                'documents_nama_dokumen_idx',
                'documents_created_at_idx',
            ] as $index) {
                if ($this->hasIndex('documents', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        Schema::table('position_histories', function (Blueprint $table): void {
            foreach ([
                'ph_employee_latest_idx',
                'ph_unit_kerja_latest_idx',
            ] as $index) {
                if ($this->hasIndex('position_histories', $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }

    /**
     * Cek apakah index sudah ada — database-agnostic (SQLite, PostgreSQL, MySQL).
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $indexes = Schema::getIndexes($table);
            foreach ($indexes as $index) {
                if (($index['name'] ?? '') === $indexName) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable) {
            return false;
        }
    }
};
