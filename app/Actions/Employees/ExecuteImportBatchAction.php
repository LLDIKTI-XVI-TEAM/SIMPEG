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
        $skippedCount = $batch['validation']['skip_count'] ?? 0;

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
                    try {
                        $inserted = DB::transaction(fn (): bool => $this->executeValidatedRow(
                            $type,
                            $result['validated_data'],
                        ));
                    } catch (QueryException $exception) {
                        if (! $this->isDuplicateNipException($exception)) {
                            throw $exception;
                        }

                        $inserted = false;
                    }

                    $processedCount++;

                    if ($inserted) {
                        $insertedCount++;
                    } else {
                        $skippedCount++;
                        $this->markRowSkippedDuringExecution($batch['validation'], $result['row']);
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
                    'total_inserted' => $insertedCount,
                    'total_processed' => $processedCount,
                    'total_skipped' => $skippedCount,
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
                'inserted_count' => $insertedCount,
                'skipped_count' => $skippedCount,
                'row_issues' => $this->collectRowIssues($batch['validation']['results']),
                'finished_at' => now(),
            ]);

            $this->cleanupBatch($batchId, $batch['filename']);

            // Set final completed status
            $finalBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
            if ($finalBatch) {
                $finalBatch['status'] = 'completed';
                $finalBatch['progress'] = 100;
                $finalBatch['processed_count'] = $processedCount;
                $finalBatch['validation'] = $batch['validation'];
                $finalBatch['result'] = [
                    'inserted' => $insertedCount,
                    'skipped' => $skippedCount,
                    'failed' => $batch['validation']['error_count'] ?? 0,
                ];
                // Keep completed state for 10 minutes so user has time to view the result screen
                Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $finalBatch, now()->addMinutes(10));
            }

        } catch (\Throwable $exception) {
            ImportBatch::whereKey($batchId)->update([
                'status' => 'failed',
                'inserted_count' => $processedCount,
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
            'processed' => $processedCount,
            'skipped' => $batch['validation']['skip_count'] ?? 0,
            'failed' => $batch['validation']['error_count'] ?? 0,
        ];
    }

    private function executeValidatedRow(string $type, array $data): bool
    {
        if ($type === 'utama') {
            // Validasi dan job berjalan terpisah; periksa ulang agar NIP yang baru
            // tersimpan setelah validasi tetap tercatat sebagai SKIP, bukan gagal.
            if (! empty($data['nip']) && Employee::where('nip', $data['nip'])->exists()) {
                return false;
            }

            // Fallback: jika kolom 'Person' (nama_lengkap tanpa gelar) tidak diisi pada file Excel,
            // gunakan nilai nama_dengan_gelar agar kolom wajib nama_lengkap tetap terisi.
            if (empty($data['nama_lengkap']) && ! empty($data['nama_dengan_gelar'])) {
                $data['nama_lengkap'] = $data['nama_dengan_gelar'];
            }

            $aktifId = RefStatusPegawai::where('nama', 'Aktif')->value('id')
                ?? RefStatusPegawai::where('is_default', true)->value('id');

            Employee::create($data + [
                'status_pegawai_id' => $aktifId,
                'status_aktif' => 'Aktif',
                'profil_status' => 'belum_lengkap',
                'is_kinerja_baik' => true,
            ]);

            return true;
        }

        return false;
    }

    /**
     * Query driver dapat mendeteksi konflik tepat sesudah pemeriksaan ulang.
     * Hanya constraint NIP yang diterjemahkan menjadi SKIP; konflik unik lain tetap gagal.
     */
    private function isDuplicateNipException(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return in_array($exception->getCode(), ['23000', '23505'], true)
            && (str_contains($message, 'employees.nip') || str_contains($message, 'employees_nip_unique'));
    }

    /**
     * Simpan perubahan status untuk laporan cache dan laporan permanen.
     *
     * @param  array{valid_count:int, error_count:int, skip_count:int, results:array<int, array<string, mixed>>}  $validation
     */
    private function markRowSkippedDuringExecution(array &$validation, int $rowNumber): void
    {
        foreach ($validation['results'] as &$result) {
            if (($result['row'] ?? null) !== $rowNumber) {
                continue;
            }

            $result['status'] = 'skip';
            $result['errors'] = ['NIP' => ['NIP sudah terdaftar di database.']];
            unset($result['validated_data']);
            $validation['valid_count']--;
            $validation['skip_count']++;

            return;
        }
        unset($result);
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
