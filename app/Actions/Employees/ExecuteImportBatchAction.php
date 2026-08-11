<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\RefStatusPegawai;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Employees\TmtCalculatorService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExecuteImportBatchAction
{
    public function __construct(private readonly TmtCalculatorService $tmtCalculator) {}

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
        $validationSkippedCount = (int) ($batch['validation']['skip_count'] ?? 0);
        $validationFailedCount = (int) ($batch['validation']['error_count'] ?? 0);
        $validationRowIssues = $this->collectRowIssues($batch['validation']['results']);

        // Hanya satu worker boleh memindahkan batch queued ke processing.
        $claimed = ImportBatch::query()
            ->whereKey($batchId)
            ->where('status', 'queued')
            ->update([
                'status' => 'processing',
                'valid_count' => $batch['validation']['valid_count'] ?? $totalRows,
                'error_message' => null,
                'finished_at' => null,
            ]);

        if ($claimed !== 1) {
            return $this->existingExecutionResult($batchId);
        }

        $executionBatch = ImportBatch::query()->findOrFail($batchId);
        if ($executionBatch->started_at === null || $executionBatch->row_issues === null) {
            $executionBatch->started_at ??= now();
            $executionBatch->row_issues ??= $validationRowIssues;
            $executionBatch->save();
        }

        $insertedCount = (int) $executionBatch->inserted_count;
        $skippedCount = max($validationSkippedCount, (int) $executionBatch->skipped_count);
        $failedCount = max($validationFailedCount, (int) $executionBatch->failed_count);
        $rowIssues = $executionBatch->row_issues ?? $validationRowIssues;
        $processedCount = $insertedCount + max(0, $skippedCount - $validationSkippedCount);

        $batch['status'] = 'processing';
        $batch['progress'] = $totalRows === 0 ? 100 : (int) (($processedCount / $totalRows) * 100);
        $batch['processed_count'] = $processedCount;
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        try {
            if ($validRows !== []) {
                foreach (array_slice($validRows, $processedCount) as $result) {
                    $checkpoint = $this->executeAndCheckpointRow(
                        $batchId,
                        $type,
                        $result,
                        $processedCount,
                        $insertedCount,
                        $skippedCount,
                        $failedCount,
                        $rowIssues,
                    );
                    $processedCount = $checkpoint['processed'];
                    $insertedCount = $checkpoint['inserted'];
                    $skippedCount = $checkpoint['skipped'];
                    $rowIssues = $checkpoint['row_issues'];

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

            // Audit ringkasan dan status terminal harus commit bersama agar retry tidak menggandakan audit.
            DB::transaction(function () use (
                $batchId,
                $user,
                $type,
                $finalCounts,
                $processedCount,
                $batch,
                $ipAddress,
                $userAgent,
                $rowIssues,
            ): void {
                AuditService::logAsOrFail(
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

                $completed = ImportBatch::query()
                    ->whereKey($batchId)
                    ->where('status', 'processing')
                    ->update([
                        'status' => 'completed',
                        'inserted_count' => $finalCounts['inserted'],
                        'skipped_count' => $finalCounts['skipped'],
                        'failed_count' => $finalCounts['failed'],
                        'row_issues' => $rowIssues,
                        'finished_at' => now(),
                    ]);

                if ($completed !== 1) {
                    throw new \RuntimeException('Status batch import berubah sebelum penyelesaian dapat dicatat.');
                }
            });

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
            // Exception masih dapat dicoba ulang oleh queue; hanya callback failed() yang terminal.
            ImportBatch::query()
                ->whereKey($batchId)
                ->where('status', 'processing')
                ->update([
                    'status' => 'queued',
                    'error_message' => $exception->getMessage(),
                    'finished_at' => null,
                ]);

            $failedBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
            if ($failedBatch) {
                $checkpoint = ImportBatch::query()->find($batchId);
                $failedBatch['status'] = 'queued';
                $failedBatch['processed_count'] = $checkpoint?->inserted_count
                    + max(0, ($checkpoint?->skipped_count ?? 0) - $validationSkippedCount);
                $failedBatch['error_message'] = $exception->getMessage();
                Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $failedBatch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));
            }
            throw $exception;
        }

        return [
            'executed' => true,
            'status' => 'completed',
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
     * Menyatukan mutasi pegawai dan checkpoint batch agar retry selalu mulai setelah baris yang sudah commit.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $rowIssues
     * @return array{processed: int, inserted: int, skipped: int, row_issues: array<int, array<string, mixed>>}
     */
    private function executeAndCheckpointRow(
        string $batchId,
        string $type,
        array $row,
        int $processedCount,
        int $insertedCount,
        int $skippedCount,
        int $failedCount,
        array $rowIssues,
    ): array {
        return DB::transaction(function () use (
            $batchId,
            $type,
            $row,
            $processedCount,
            $insertedCount,
            $skippedCount,
            $failedCount,
            $rowIssues,
        ): array {
            $outcome = $this->executeValidatedRow($type, $row);
            $processedCount++;

            if ($outcome['status'] === 'inserted') {
                $insertedCount++;
            } else {
                $skippedCount++;
                if (isset($outcome['issue'])) {
                    $rowIssues[] = $outcome['issue'];
                }
            }

            $checkpointed = ImportBatch::query()
                ->whereKey($batchId)
                ->where('status', 'processing')
                ->update([
                    'inserted_count' => $insertedCount,
                    'skipped_count' => $skippedCount,
                    'failed_count' => $failedCount,
                    'row_issues' => $rowIssues,
                ]);

            if ($checkpointed !== 1) {
                throw new \RuntimeException('Checkpoint batch import gagal disimpan.');
            }

            return [
                'processed' => $processedCount,
                'inserted' => $insertedCount,
                'skipped' => $skippedCount,
                'row_issues' => $rowIssues,
            ];
        });
    }

    /** Mengembalikan hasil pertama tanpa menjalankan ulang side effect batch. */
    private function existingExecutionResult(string $batchId): array
    {
        $batch = ImportBatch::query()->find($batchId);

        if ($batch === null) {
            throw ValidationException::withMessages([
                'message' => ['Batch import belum diklaim untuk antrean.'],
            ]);
        }

        return [
            'executed' => false,
            'status' => $batch->status,
            'message' => 'Batch import sudah diproses atau sedang diproses oleh worker lain.',
            'inserted' => $batch->inserted_count,
            'inserted_count' => $batch->inserted_count,
            'processed' => $batch->inserted_count + $batch->skipped_count,
            'skipped' => $batch->skipped_count,
            'skipped_count' => $batch->skipped_count,
            'failed' => $batch->failed_count,
            'failed_count' => $batch->failed_count,
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
                $employee = Employee::create($data + [
                    'status_pegawai_id' => $aktifId,
                    'status_aktif' => 'Aktif',
                    'profil_status' => 'belum_lengkap',
                    'is_kinerja_baik' => true,
                ]);

                // Import Data Utama tidak menjalankan kalkulator TMT penuh. Hanya provenance
                // tanggal pensiun resmi yang dicatat agar snapshot lain tidak dihitung atau ditimpa.
                if ($employee->tanggal_pensiun !== null) {
                    $this->tmtCalculator->recordImportedPensionDate($employee);
                }
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
