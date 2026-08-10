<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\RefStatusPegawai;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExecuteImportBatchAction
{
    /**
     * Execute the validated batch.
     *
     *
     * @throws ValidationException
     */
    public function execute(string $batchId, ?User $user, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        if ($batch === null) {
            abort(404, 'Batch import tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
        }

        if ($batch['user_id'] !== null && ($user === null || $batch['user_id'] !== $user->id)) {
            abort(403, 'Anda tidak memiliki akses ke batch import ini.');
        }

        if ($batch['validation'] === null) {
            throw ValidationException::withMessages([
                'message' => ['Data belum divalidasi. Jalankan validasi terlebih dahulu.'],
            ]);
        }

        $type = $batch['type'] ?? 'utama';
        $validRows = array_values(array_filter(
            $batch['validation']['results'],
            fn (array $result) => $result['status'] === 'valid' && isset($result['validated_data']),
        ));
        $totalRows = count($validRows);
        $processedCount = 0;
        $insertedCount = 0;
        $skippedCount = (int) ($batch['validation']['skip_count'] ?? 0);
        $failedCount = (int) ($batch['validation']['error_count'] ?? 0);
        $rowIssues = $this->collectRowIssues($batch['validation']['results']);

        // Update status to processing
        $batch['status'] = 'processing';
        $batch['progress'] = 0;
        $batch['processed_count'] = 0;
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        // Rekam batch ke database agar laporan hasil tetap tersedia setelah cache kedaluwarsa.
        ImportBatch::updateOrCreate(['id' => $batchId], [
            'user_id' => $user?->id,
            'filename' => $batch['filename'],
            'type' => $type,
            'status' => 'processing',
            'total_rows' => $batch['total_rows'] ?? count($batch['rows'] ?? []),
            'valid_count' => $batch['validation']['valid_count'] ?? $totalRows,
            'inserted_count' => 0,
            'skipped_count' => $skippedCount,
            'failed_count' => $failedCount,
            'row_issues' => $rowIssues,
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        try {
            if ($validRows !== []) {
                foreach ($validRows as $result) {
                    $outcome = DB::transaction(
                        fn (): array => $this->executeValidatedRow($type, $result),
                    );

                    $processedCount++;
                    if ($outcome['status'] === 'inserted') {
                        $insertedCount++;
                    } else {
                        $skippedCount++;
                        if (isset($outcome['issue'])) {
                            $rowIssues[] = $outcome['issue'];
                        }
                    }

                    // Update progress in cache (di luar transaction agar terlihat real-time)
                    $currentBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
                    if ($currentBatch) {
                        $currentBatch['processed_count'] = $processedCount;
                        $currentBatch['progress'] = (int) (($processedCount / $totalRows) * 100);
                        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $currentBatch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));
                    }
                }
            }

            $finalCounts = [
                'inserted' => $insertedCount,
                'skipped' => $skippedCount,
                'failed' => $failedCount,
            ];

            AuditService::logAs(
                $user?->id ?? 'system',
                $user?->name ?? 'System Queue',
                'IMPORT',
                'Employee',
                null,
                null,
                [
                    'template_type' => $type,
                    'template_label' => UploadImportBatchAction::TEMPLATE_LABELS[$type] ?? UploadImportBatchAction::TEMPLATE_LABELS['utama'],
                    'total_inserted' => $finalCounts['inserted'],
                    'total_processed' => $processedCount,
                    'total_skipped' => $finalCounts['skipped'],
                    'total_failed' => $finalCounts['failed'],
                    'filename' => $batch['filename'],
                ],
                null,
                $ipAddress,
                $userAgent
            );

            // Persist hasil akhir sebelum file sumber dihapus supaya laporan tidak pernah hilang.
            ImportBatch::whereKey($batchId)->update([
                'status' => 'completed',
                'inserted_count' => $finalCounts['inserted'],
                'skipped_count' => $finalCounts['skipped'],
                'failed_count' => $finalCounts['failed'],
                'row_issues' => $rowIssues,
                'finished_at' => now(),
            ]);

            $this->cleanupBatch($batchId, $batch['filename']);

            // Set final completed status
            $finalBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
            if ($finalBatch) {
                $finalBatch['status'] = 'completed';
                $finalBatch['progress'] = 100;
                $finalBatch['processed_count'] = $processedCount;
                $finalBatch['result'] = $finalCounts;
                $finalBatch['row_issues'] = $rowIssues;
                // Keep completed state for 10 minutes so user has time to view the result screen
                Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $finalBatch, now()->addMinutes(10));
            }

        } catch (\Throwable $exception) {
            ImportBatch::whereKey($batchId)->update([
                'status' => 'failed',
                'inserted_count' => $insertedCount,
                'skipped_count' => $skippedCount,
                'failed_count' => $failedCount,
                'row_issues' => $rowIssues,
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            $failedBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
            if ($failedBatch) {
                $failedBatch['status'] = 'failed';
                $failedBatch['error_message'] = $exception->getMessage();
                Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $failedBatch, now()->addMinutes(10));
            }
            throw $exception;
        }

        return [
            'message' => 'Import selesai.',
            'inserted' => $finalCounts['inserted'],
            'inserted_count' => $finalCounts['inserted'],
            'processed' => $processedCount,
            'skipped' => $finalCounts['skipped'],
            'skipped_count' => $finalCounts['skipped'],
            'failed' => $finalCounts['failed'],
            'failed_count' => $finalCounts['failed'],
        ];
    }

    /**
     * Menjalankan satu baris tervalidasi dan mengembalikan outcome aktual untuk rekonsiliasi counter.
     *
     * @param  array<string, mixed>  $row
     * @return array{status: 'inserted'|'skipped', issue?: array<string, mixed>}
     */
    private function executeValidatedRow(string $type, array $row): array
    {
        if ($type === 'utama') {
            $data = $row['validated_data'];

            // Fallback: jika kolom 'Person' (nama_lengkap tanpa gelar) tidak diisi pada file Excel,
            // gunakan nilai nama_dengan_gelar agar kolom wajib nama_lengkap tetap terisi.
            if (empty($data['nama_lengkap']) && ! empty($data['nama_dengan_gelar'])) {
                $data['nama_lengkap'] = $data['nama_dengan_gelar'];
            }

            // Validasi dan eksekusi terpisah waktu; NIP dapat muncul setelah preview dinyatakan valid.
            if (! empty($data['nip']) && Employee::where('nip', $data['nip'])->exists()) {
                return $this->duplicateNipOutcome($row);
            }

            $aktifId = RefStatusPegawai::where('nama', 'Aktif')->value('id')
                ?? RefStatusPegawai::where('is_default', true)->value('id');

            try {
                Employee::create($data + [
                    'status_pegawai_id' => $aktifId,
                    'status_aktif' => 'Aktif',
                    'profil_status' => 'belum_lengkap',
                    'is_kinerja_baik' => true,
                ]);
            } catch (QueryException $exception) {
                // Hanya tabrakan constraint NIP yang merupakan outcome skip; pelanggaran lain
                // harus tetap gagal agar masalah integritas data tidak tersamarkan.
                if (! $this->isDuplicateNipViolation($exception)) {
                    throw $exception;
                }

                return $this->duplicateNipOutcome($row);
            }

            return ['status' => 'inserted'];
        }

        return ['status' => 'skipped'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{status: 'skipped', issue: array<string, mixed>}
     */
    private function duplicateNipOutcome(array $row): array
    {
        return [
            'status' => 'skipped',
            'issue' => [
                'row' => $row['row'] ?? null,
                'nama' => $row['nama'] ?? '-',
                'kategori' => 'dilewati',
                'errors' => [
                    'NIP' => ['NIP sudah terdaftar saat proses import dijalankan.'],
                ],
            ],
        ];
    }

    /** Memastikan unique violation berasal dari constraint NIP pegawai, bukan kolom unik lain. */
    private function isDuplicateNipViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverDiagnostic = (string) ($exception->errorInfo[2] ?? '');

        if ($sqlState === '23505') {
            preg_match('/unique constraint ["\']([^"\']+)["\']/i', $driverDiagnostic, $matches);

            return ($matches[1] ?? null) === 'employees_nip_unique';
        }

        return $sqlState === '23000'
            && preg_match('/unique constraint failed:\s*employees\.nip\b/i', $driverDiagnostic) === 1;
    }

    /**
     * Kumpulkan baris bermasalah dari hasil validasi untuk laporan permanen:
     * baris gagal validasi dan baris yang dilewati (NIP sudah terdaftar).
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function collectRowIssues(array $results): array
    {
        $issues = [];
        foreach ($results as $result) {
            if (! in_array($result['status'], ['error', 'skip'], true)) {
                continue;
            }

            $issues[] = [
                'row' => $result['row'] ?? null,
                'nama' => $result['nama'] ?? '-',
                'kategori' => $result['status'] === 'skip' ? 'dilewati' : 'gagal',
                'errors' => $result['errors'] ?? [],
            ];
        }

        return $issues;
    }

    private function cleanupBatch(string $batchId, string $filename): void
    {
        $storedName = $batchId.'_'.$filename;
        if (Storage::disk('local')->exists(UploadImportBatchAction::STORAGE_DIR.'/'.$storedName)) {
            Storage::disk('local')->delete(UploadImportBatchAction::STORAGE_DIR.'/'.$storedName);
        }
    }
}
