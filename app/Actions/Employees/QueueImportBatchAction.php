<?php

namespace App\Actions\Employees;

use App\Jobs\ImportEmployeeBatchJob;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueImportBatchAction
{
    /**
     * Claim one validated batch and dispatch its worker job.
     *
     * @return array{status: string, message: string}
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

        $claimed = Cache::lock("import-batch-dispatch:{$batchId}", 10)->block(3, function () use ($batchId, $batch, $user): bool {
            return DB::transaction(function () use ($batchId, $batch, $user): bool {
                $model = ImportBatch::query()->lockForUpdate()->find($batchId);

                if ($model === null) {
                    $model = ImportBatch::create([
                        'id' => $batchId,
                        'user_id' => $user?->id,
                        'filename' => $batch['filename'],
                        'type' => $batch['type'] ?? 'utama',
                        'status' => 'validated',
                        'total_rows' => $batch['total_rows'] ?? 0,
                        'valid_count' => $batch['validation']['valid_count'] ?? 0,
                        'skipped_count' => $batch['validation']['skip_count'] ?? 0,
                        'failed_count' => $batch['validation']['error_count'] ?? 0,
                        'row_issues' => [],
                    ]);
                }

                if (in_array($model->status, ['queued', 'processing', 'completed'], true)) {
                    return false;
                }

                $model->update(['status' => 'queued', 'started_at' => null, 'finished_at' => null, 'error_message' => null]);

                return true;
            });
        });

        if (! $claimed) {
            return [
                'status' => ImportBatch::find($batchId)?->status ?? 'queued',
                'message' => 'Batch import sudah sedang diproses atau telah selesai.',
            ];
        }

        $batch['status'] = 'queued';
        $batch['progress'] = 0;
        $batch['processed_count'] = 0;
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        try {
            ImportEmployeeBatchJob::dispatch($batchId, $user?->id, $ipAddress, $userAgent);
        } catch (\Throwable $exception) {
            $currentStatus = ImportBatch::find($batchId)?->status;
            if ($currentStatus === 'completed') {
                $completedBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId) ?? $batch;
                $completedBatch['status'] = 'completed';
                Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $completedBatch, now()->addMinutes(10));

                return [
                    'status' => 'completed',
                    'message' => 'Import telah selesai; kegagalan terjadi setelah proses impor.',
                ];
            }

            ImportBatch::whereKey($batchId)->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            $batch['status'] = 'failed';
            $batch['error_message'] = $exception->getMessage();
            Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

            return [
                'status' => 'failed',
                'message' => 'Batch import gagal dimasukkan ke antrean. Silakan coba lagi.',
            ];
        }

        return [
            'status' => 'queued',
            'message' => 'Proses impor telah dimasukkan ke dalam antrean. Anda dapat meninggalkan halaman ini.',
        ];
    }
}
