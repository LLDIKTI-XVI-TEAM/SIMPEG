<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\RefStatusPegawai;
use App\Models\User;
use App\Services\AuditService;
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
        $raceConditionSkipCount = 0;

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
            'skipped_count' => $batch['validation']['skip_count'] ?? 0,
            'failed_count' => $batch['validation']['error_count'] ?? 0,
            'row_issues' => $this->collectRowIssues($batch['validation']['results']),
            'error_message' => null,
            'started_at' => now(),
            'finished_at' => null,
        ]);

        try {
            if ($validRows !== []) {
                foreach ($validRows as $result) {
                    $wasSkipped = false;
                    DB::transaction(function () use ($type, $result, &$wasSkipped): void {
                        $wasSkipped = $this->executeValidatedRow($type, $result['validated_data']);
                    });

                    if ($wasSkipped) {
                        $raceConditionSkipCount++;
                    } else {
                        $processedCount++;
                    }

                    // Update progress in cache (di luar transaction agar terlihat real-time)
                    $currentBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
                    if ($currentBatch) {
                        $currentBatch['processed_count'] = $processedCount;
                        $currentBatch['race_skip_count'] = $raceConditionSkipCount;
                        $currentBatch['progress'] = (int) ((($processedCount + $raceConditionSkipCount) / $totalRows) * 100);
                        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $currentBatch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));
                    }
                }
            }

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
                    'total_inserted' => $processedCount,
                    'total_processed' => $processedCount + $raceConditionSkipCount,
                    'total_skipped' => ($batch['validation']['skip_count'] ?? 0) + $raceConditionSkipCount,
                    'total_race_skipped' => $raceConditionSkipCount,
                    'total_failed' => $batch['validation']['error_count'] ?? 0,
                    'filename' => $batch['filename'],
                ],
                null,
                $ipAddress,
                $userAgent
            );

            // Persist hasil akhir sebelum file sumber dihapus supaya laporan tidak pernah hilang.
            ImportBatch::whereKey($batchId)->update([
                'status' => 'completed',
                'inserted_count' => $processedCount,
                'skipped_count' => ($batch['validation']['skip_count'] ?? 0) + $raceConditionSkipCount,
                'finished_at' => now(),
            ]);

            $this->cleanupBatch($batchId, $batch['filename']);

            // Set final completed status
            $finalBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
            if ($finalBatch) {
                $finalBatch['status'] = 'completed';
                $finalBatch['progress'] = 100;
                $finalBatch['processed_count'] = $processedCount;
                $finalBatch['result'] = [
                    'inserted' => $processedCount,
                    'skipped' => ($batch['validation']['skip_count'] ?? 0) + $raceConditionSkipCount,
                    'failed' => $batch['validation']['error_count'] ?? 0,
                ];
                // Keep completed state for 10 minutes so user has time to view the result screen
                Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $finalBatch, now()->addMinutes(10));
            }

        } catch (\Throwable $exception) {
            ImportBatch::whereKey($batchId)->update([
                'status' => 'failed',
                'inserted_count' => $processedCount,
                'skipped_count' => ($batch['validation']['skip_count'] ?? 0) + $raceConditionSkipCount,
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
            'inserted' => $processedCount,
            'processed' => $processedCount + $raceConditionSkipCount,
            'skipped' => ($batch['validation']['skip_count'] ?? 0) + $raceConditionSkipCount,
            'failed' => $batch['validation']['error_count'] ?? 0,
        ];
    }

    /**
     * Execute validated row and return skip status.
     *
     * @return bool True if row was skipped due to race condition, false if inserted
     */
    private function executeValidatedRow(string $type, array $data): bool
    {
        if ($type === 'utama') {
            // Fallback: jika kolom 'Person' (nama_lengkap tanpa gelar) tidak diisi pada file Excel,
            // gunakan nilai nama_dengan_gelar agar kolom wajib nama_lengkap tetap terisi.
            if (empty($data['nama_lengkap']) && ! empty($data['nama_dengan_gelar'])) {
                $data['nama_lengkap'] = $data['nama_dengan_gelar'];
            }

            // K-US-02: Insert-time duplicate guard untuk race condition protection
            // Jika NIP sudah exists (race condition antara validasi dan insert),
            // skip insertion secara graceful daripada fail entire batch
            if (! empty($data['nip']) && Employee::where('nip', $data['nip'])->exists()) {
                // Return true to indicate this row was skipped
                return true;
            }

            $aktifId = RefStatusPegawai::where('nama', 'Aktif')->value('id')
                ?? RefStatusPegawai::where('is_default', true)->value('id');

            Employee::create($data + [
                'status_pegawai_id' => $aktifId,
                'status_aktif' => 'Aktif',
                'profil_status' => 'belum_lengkap',
                'is_kinerja_baik' => true,
            ]);
        }

        // Return false to indicate row was successfully inserted
        return false;
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
