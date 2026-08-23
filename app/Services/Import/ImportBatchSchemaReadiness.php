<?php

namespace App\Services\Import;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\Schema;

/**
 * Memeriksa apakah tabel import_batches sudah memiliki seluruh kolom durability
 * yang dibutuhkan untuk mengklaim batch, memegang lease worker, dan memulihkan publish job.
 *
 * Pemeriksaan ini murni membaca metadata schema. Ia tidak pernah menjalankan migration
 * dan tidak mengubah database, karena perubahan schema harus tetap menjadi langkah rilis
 * yang eksplisit, bukan efek samping dari request pengguna.
 */
class ImportBatchSchemaReadiness
{
    /**
     * Kolom yang wajib ada sebelum batch import boleh diklaim.
     *
     * Daftar ini adalah kontrak antara migration dan payload claim pada QueueImportBatchAction.
     * Bila kode import membutuhkan kolom baru, kolom tersebut harus ditambahkan di sini
     * agar ketidaksinkronan schema terdeteksi sebelum insert, bukan saat query gagal.
     *
     * @var list<string>
     */
    public const REQUIRED_COLUMNS = [
        'execution_payload',
        'processed_valid_count',
        'processing_token',
        'lease_expires_at',
        'completion_notified_at',
        'failure_notified_at',
        'job_publish_attempted_at',
        'job_published_at',
        'job_publish_lease_expires_at',
        'job_publish_attempts',
        'processing_delivery_id',
        'processing_attempt',
        'queued_original_role',
        'queued_effective_role',
    ];

    /**
     * Mengembalikan kolom wajib yang belum tersedia pada database aktif.
     *
     * Hasil diurutkan agar log operasional dan test bersifat deterministik.
     * Sengaja tidak di-cache supaya schema yang baru dimigrasikan langsung dianggap siap
     * tanpa perlu membersihkan cache aplikasi.
     *
     * @return list<string>
     */
    public function missingColumns(): array
    {
        $model = new ImportBatch;
        $schema = Schema::connection($model->getConnectionName());
        $table = $model->getTable();

        if (! $schema->hasTable($table)) {
            return self::REQUIRED_COLUMNS;
        }

        $existingColumns = array_map(
            // Normalisasi huruf kecil menjaga perbandingan tetap benar bila driver database
            // mengembalikan nama kolom dengan case yang berbeda.
            static fn (string $column): string => strtolower($column),
            $schema->getColumnListing($table),
        );

        $missingColumns = array_values(array_diff(self::REQUIRED_COLUMNS, $existingColumns));
        sort($missingColumns);

        return $missingColumns;
    }

    /** Schema dianggap siap hanya bila seluruh kolom wajib tersedia (fail-closed). */
    public function isReady(): bool
    {
        return $this->missingColumns() === [];
    }
}
