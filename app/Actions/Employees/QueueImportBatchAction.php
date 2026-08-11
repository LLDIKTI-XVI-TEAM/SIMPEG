<?php

namespace App\Actions\Employees;

use App\Jobs\ImportEmployeeBatchJob;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QueueImportBatchAction
{
    /**
     * Mengklaim batch secara atomik sebelum dispatch agar request ganda tidak membuat job ganda.
     * Primary key batch menjadi idempotency key lintas proses pada PostgreSQL.
     *
     * @return array{status: string, message: string}
     */
    public function execute(
        string $batchId,
        ?User $user,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $cacheKey = UploadImportBatchAction::CACHE_PREFIX.$batchId;
        $batch = Cache::get($cacheKey);

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

        $originalBatch = $batch;
        $claimed = false;
        $processingToken = (string) Str::uuid();
        $job = new ImportEmployeeBatchJob($batchId, $user?->id, $ipAddress, $userAgent, $processingToken);

        try {
            DB::transaction(function () use (
                $batchId,
                $user,
                $cacheKey,
                $batch,
                $processingToken,
                &$claimed,
            ): void {
                $now = now();
                $importBatch = new ImportBatch([
                    'id' => $batchId,
                    'user_id' => $user?->id,
                    'filename' => $batch['filename'],
                    'type' => $batch['type'] ?? 'utama',
                    'status' => 'queued',
                    'total_rows' => $batch['total_rows'] ?? count($batch['rows'] ?? []),
                    'valid_count' => $batch['validation']['valid_count'] ?? 0,
                    'inserted_count' => 0,
                    'skipped_count' => $batch['validation']['skip_count'] ?? 0,
                    'failed_count' => $batch['validation']['error_count'] ?? 0,
                    'processed_valid_count' => 0,
                    'processing_token' => $processingToken,
                    'row_issues' => $this->collectRowIssues($batch['validation']['results'] ?? []),
                    'error_message' => null,
                    // Payload tervalidasi berisi data pegawai sensitif, sehingga wajib melewati encrypted cast model.
                    'execution_payload' => [
                        'filename' => $batch['filename'],
                        'type' => $batch['type'] ?? 'utama',
                        'validation' => $batch['validation'],
                    ],
                    'started_at' => null,
                    'finished_at' => null,
                ]);
                $importBatch->forceFill(['created_at' => $now, 'updated_at' => $now]);
                $claimed = ImportBatch::query()->insertOrIgnore($importBatch->getAttributes()) === 1;

                if (! $claimed) {
                    return;
                }

                $queuedBatch = $batch;
                $queuedBatch['status'] = 'queued';
                $queuedBatch['progress'] = 0;
                $queuedBatch['processed_count'] = 0;
                Cache::put($cacheKey, $queuedBatch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));
            });

            if ($claimed) {
                // Dispatch setelah transaksi internal agar unique lock mengikuti transaksi caller terluar.
                dispatch($job);
            }
        } catch (\Throwable $exception) {
            if ($claimed) {
                Cache::put($cacheKey, $originalBatch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));
            }

            throw $exception;
        }

        if (! $claimed) {
            return $this->existingStatus($batchId, $cacheKey, $batch);
        }

        return [
            'status' => 'queued',
            'message' => 'Proses impor telah dimasukkan ke dalam antrean. Anda dapat meninggalkan halaman ini.',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    private function collectRowIssues(array $results): array
    {
        return collect($results)
            ->filter(fn (array $result): bool => in_array($result['status'] ?? null, ['error', 'skip'], true))
            ->map(fn (array $result): array => [
                'row' => $result['row'] ?? null,
                'nama' => $result['nama'] ?? '-',
                'kategori' => ($result['status'] ?? null) === 'skip' ? 'dilewati' : 'gagal',
                'errors' => $result['errors'] ?? [],
            ])
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $cachedBatch */
    private function existingStatus(string $batchId, string $cacheKey, array $cachedBatch): array
    {
        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $cachedBatch['status'] = $persistedBatch->status;

        if ($persistedBatch->status === 'completed') {
            $cachedBatch['progress'] = 100;
            $cachedBatch['result'] = [
                'inserted' => $persistedBatch->inserted_count,
                'skipped' => $persistedBatch->skipped_count,
                'failed' => $persistedBatch->failed_count,
            ];
            $cachedBatch['row_issues'] = $persistedBatch->row_issues;
        }

        Cache::put($cacheKey, $cachedBatch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        return [
            'status' => $persistedBatch->status,
            'message' => 'Batch import ini sudah diklaim dan tidak dijadwalkan ulang.',
        ];
    }
}
