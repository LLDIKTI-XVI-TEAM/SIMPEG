<?php

namespace App\Actions\Employees;

use App\Models\Employee;
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

        // Update status to processing
        $batch['status'] = 'processing';
        $batch['progress'] = 0;
        $batch['processed_count'] = 0;
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        try {
            if ($validRows !== []) {
                DB::transaction(function () use ($validRows, $type, $batchId, $totalRows, &$processedCount): void {
                    foreach ($validRows as $result) {
                        $this->executeValidatedRow($type, $result['validated_data']);
                        $processedCount++;

                        // Update progress in cache
                        $currentBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
                        if ($currentBatch) {
                            $currentBatch['processed_count'] = $processedCount;
                            $currentBatch['progress'] = (int) (($processedCount / $totalRows) * 100);
                            Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $currentBatch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));
                        }
                    }
                });
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
                    'total_processed' => $processedCount,
                    'total_skipped' => $batch['validation']['skip_count'] ?? 0,
                    'total_failed' => $batch['validation']['error_count'] ?? 0,
                    'filename' => $batch['filename'],
                ],
                null,
                $ipAddress,
                $userAgent
            );

            $this->cleanupBatch($batchId, $batch['filename']);

            // Set final completed status
            $finalBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
            if ($finalBatch) {
                $finalBatch['status'] = 'completed';
                $finalBatch['progress'] = 100;
                $finalBatch['processed_count'] = $processedCount;
                $finalBatch['result'] = [
                    'inserted' => $processedCount,
                    'skipped' => $batch['validation']['skip_count'] ?? 0,
                    'failed' => $batch['validation']['error_count'] ?? 0,
                ];
                // Keep completed state for 10 minutes so user has time to view the result screen
                Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $finalBatch, now()->addMinutes(10));
            }

        } catch (\Throwable $exception) {
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
            'processed' => $processedCount,
            'skipped' => $batch['validation']['skip_count'] ?? 0,
            'failed' => $batch['validation']['error_count'] ?? 0,
        ];
    }

    private function executeValidatedRow(string $type, array $data): void
    {
        if ($type === 'utama') {
            // Fallback: jika kolom 'Person' (nama_lengkap tanpa gelar) tidak diisi pada file Excel,
            // gunakan nilai nama_dengan_gelar agar kolom wajib nama_lengkap tetap terisi.
            if (empty($data['nama_lengkap']) && !empty($data['nama_dengan_gelar'])) {
                $data['nama_lengkap'] = $data['nama_dengan_gelar'];
            }

            Employee::create($data + [
                'status_aktif'   => 'Aktif',
                'profil_status'  => 'belum_lengkap',
                'is_kinerja_baik' => true,
            ]);
        }
    }

    private function cleanupBatch(string $batchId, string $filename): void
    {
        $storedName = $batchId.'_'.$filename;
        if (Storage::disk('local')->exists(UploadImportBatchAction::STORAGE_DIR.'/'.$storedName)) {
            Storage::disk('local')->delete(UploadImportBatchAction::STORAGE_DIR.'/'.$storedName);
        }
    }
}
