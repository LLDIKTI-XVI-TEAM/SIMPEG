<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\RefStatusPegawai;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Employees\TmtCalculatorService;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExecuteImportBatchAction
{
    /**
     * Lease berakhir sebelum pesan boleh dikirim ulang oleh semua backend queue yang didukung.
     * Nilai ini harus lebih kecil dari `retry_after` koneksi queue agar worker aktif tidak
     * kehilangan kepemilikan selama eksekusi normal berjalan.
     */
    private const LEASE_SECONDS = 150;

    public function __construct(private readonly TmtCalculatorService $tmtCalculator) {}

    /**
     * Execute the validated batch.
     *
     *
     * @throws ValidationException
     */
    public function execute(
        string $batchId,
        ?User $user,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $processingToken = null,
    ): array {
        $executionBatch = ImportBatch::query()->find($batchId);

        if ($executionBatch === null) {
            throw ValidationException::withMessages([
                'message' => ['Batch import belum diklaim untuk antrean.'],
            ]);
        }

        if ($executionBatch->user_id !== null && ($user === null || $executionBatch->user_id !== $user->id)) {
            abort(403, 'Anda tidak memiliki akses ke batch import ini.');
        }

        if ($executionBatch->status === 'completed') {
            return $this->existingExecutionResult($batchId);
        }

        $executionPayload = $executionBatch->execution_payload;
        if (! is_array($executionPayload) || ! is_array($executionPayload['validation'] ?? null)) {
            throw ValidationException::withMessages([
                'message' => ['Payload eksekusi batch import tidak tersedia.'],
            ]);
        }

        $validation = $executionPayload['validation'];
        $type = (string) ($executionPayload['type'] ?? $executionBatch->type ?? 'utama');
        $validRows = array_values(array_filter(
            $validation['results'] ?? [],
            fn (array $result): bool => ($result['status'] ?? null) === 'valid' && isset($result['validated_data']),
        ));
        $totalRows = count($validRows);
        $attemptToken = $processingToken ?? (string) Str::uuid();

        // Token attempt dan lease membuat worker baru dapat memulihkan hard crash tanpa mengambil alih worker aktif.
        $claimed = ImportBatch::query()
            ->whereKey($batchId)
            ->where(function ($query) use ($attemptToken): void {
                $query->where(function ($queued) use ($attemptToken): void {
                    $queued->where('status', 'queued')
                        ->where(function ($owner) use ($attemptToken): void {
                            $owner->whereNull('processing_token')
                                ->orWhere('processing_token', $attemptToken);
                        });
                })
                    // Token yang sama hanya mengidentifikasi payload, bukan membuktikan worker lama
                    // sudah berhenti. Semua redelivery wajib menunggu lease aktif kedaluwarsa.
                    ->orWhere(function ($expired): void {
                        $expired->where('status', 'processing')
                            ->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', now());
                    });
            })
            ->update([
                'status' => 'processing',
                'processing_token' => $attemptToken,
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                'valid_count' => $validation['valid_count'] ?? $totalRows,
                'error_message' => null,
                'finished_at' => null,
            ]);

        if ($claimed !== 1) {
            return $this->existingExecutionResult($batchId);
        }

        $executionBatch->refresh();
        if ($executionBatch->started_at === null) {
            $executionBatch->started_at = now();
            $executionBatch->save();
        }

        $insertedCount = (int) $executionBatch->inserted_count;
        $skippedCount = (int) $executionBatch->skipped_count;
        $failedCount = (int) $executionBatch->failed_count;
        $processedCount = (int) $executionBatch->processed_valid_count;
        $this->projectCache($executionBatch, $validation);

        try {
            foreach (array_slice($validRows, $processedCount) as $result) {
                $checkpoint = $this->executeAndCheckpointRow(
                    $batchId,
                    $attemptToken,
                    $type,
                    $result,
                    $user,
                    $ipAddress,
                    $userAgent,
                );
                $processedCount = $checkpoint['processed'];
                $insertedCount = $checkpoint['inserted'];
                $skippedCount = $checkpoint['skipped'];
                $failedCount = $checkpoint['failed'];
                $this->projectCache(ImportBatch::query()->findOrFail($batchId), $validation);
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
                $attemptToken,
                $ipAddress,
                $userAgent,
            ): void {
                $batch = ImportBatch::query()->lockForUpdate()->findOrFail($batchId);
                if ($batch->status !== 'processing' || $batch->processing_token !== $attemptToken) {
                    throw new \RuntimeException('Attempt import kehilangan kepemilikan sebelum penyelesaian.');
                }

                AuditService::logAsOrFail(
                    $user?->id ?? 'system',
                    $user?->name ?? 'System Queue',
                    'IMPORT',
                    'Employee',
                    null,
                    null,
                    [
                        'batch_id' => $batchId,
                        'scope' => 'batch_summary',
                        'template_type' => $type,
                        'template_label' => UploadImportBatchAction::TEMPLATE_LABELS[$type] ?? UploadImportBatchAction::TEMPLATE_LABELS['utama'],
                        'total_inserted' => $finalCounts['inserted'],
                        'total_processed' => $processedCount,
                        'total_skipped' => $finalCounts['skipped'],
                        'total_failed' => $finalCounts['failed'],
                        'filename' => $batch->filename,
                    ],
                    null,
                    $ipAddress,
                    $userAgent
                );

                $batch->forceFill([
                    'status' => 'completed',
                    'processing_token' => null,
                    'lease_expires_at' => null,
                    'execution_payload' => null,
                    'finished_at' => now(),
                    'error_message' => null,
                ])->save();
            });

            $this->recoverCompletedSideEffects(ImportBatch::query()->findOrFail($batchId));

        } catch (\Throwable $exception) {
            // Hanya pemilik token attempt yang boleh melepas claim; worker lama tidak boleh menimpa attempt baru.
            ImportBatch::query()
                ->whereKey($batchId)
                ->where('status', 'processing')
                ->where('processing_token', $attemptToken)
                ->update([
                    'status' => 'queued',
                    'lease_expires_at' => null,
                    'error_message' => 'Proses import belum selesai dan akan dicoba kembali.',
                    'finished_at' => null,
                ]);

            Log::warning('Attempt import pegawai gagal dan akan dicoba kembali.', [
                'batch_id' => $batchId,
                'exception_class' => $exception::class,
            ]);

            $retryableBatch = ImportBatch::query()->find($batchId);
            if ($retryableBatch !== null && $retryableBatch->status === 'queued') {
                $this->projectCache($retryableBatch, $validation);
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
     * @return array{processed: int, inserted: int, skipped: int, failed: int}
     */
    private function executeAndCheckpointRow(
        string $batchId,
        string $attemptToken,
        string $type,
        array $row,
        ?User $user,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        return DB::transaction(function () use (
            $batchId,
            $attemptToken,
            $type,
            $row,
            $user,
            $ipAddress,
            $userAgent,
        ): array {
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($batchId);
            if ($batch->status !== 'processing' || $batch->processing_token !== $attemptToken) {
                throw new \RuntimeException('Attempt import tidak lagi memiliki batch ini.');
            }

            $outcome = $this->executeValidatedRow($type, $row);
            $processedCount = (int) $batch->processed_valid_count + 1;
            $insertedCount = (int) $batch->inserted_count;
            $skippedCount = (int) $batch->skipped_count;
            $failedCount = (int) $batch->failed_count;
            $rowIssues = $batch->row_issues ?? [];

            if ($outcome['status'] === 'inserted') {
                $insertedCount++;

                // Payload audit hanya menyimpan metadata traceability, bukan data pribadi pegawai.
                AuditService::logAsOrFail(
                    $user?->id ?? 'system',
                    $user?->name ?? 'System Queue',
                    'CREATE',
                    'Employee',
                    $outcome['employee_id'],
                    null,
                    [
                        'batch_id' => $batchId,
                        'scope' => 'row',
                        'template_type' => $type,
                        'row' => $row['row'] ?? null,
                        'outcome' => 'inserted',
                    ],
                    null,
                    $ipAddress,
                    $userAgent,
                );
            } elseif ($outcome['status'] === 'skipped') {
                $skippedCount++;
                if (isset($outcome['issue'])) {
                    $rowIssues[] = $outcome['issue'];
                }
            } else {
                $failedCount++;
                $rowIssues[] = $outcome['issue'];
            }

            $batch->forceFill([
                'processed_valid_count' => $processedCount,
                'inserted_count' => $insertedCount,
                'skipped_count' => $skippedCount,
                'failed_count' => $failedCount,
                'row_issues' => $rowIssues,
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
            ])->save();

            return [
                'processed' => $processedCount,
                'inserted' => $insertedCount,
                'skipped' => $skippedCount,
                'failed' => $failedCount,
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

        if ($batch->status === 'completed') {
            $this->recoverCompletedSideEffects($batch);
        }

        return [
            'executed' => false,
            'status' => $batch->status,
            'message' => 'Batch import sudah diproses atau sedang diproses oleh worker lain.',
            'inserted' => $batch->inserted_count,
            'inserted_count' => $batch->inserted_count,
            'processed' => $batch->processed_valid_count,
            'skipped' => $batch->skipped_count,
            'skipped_count' => $batch->skipped_count,
            'failed' => $batch->failed_count,
            'failed_count' => $batch->failed_count,
            'retry_after_seconds' => $this->retryAfterSeconds($batch),
        ];
    }

    /** Cache hanya proyeksi status; seluruh nilai pemulihan tetap berasal dari database. */
    private function projectCache(ImportBatch $batch, ?array $validation = null): void
    {
        $cacheKey = UploadImportBatchAction::CACHE_PREFIX.$batch->id;
        $cached = Cache::get($cacheKey) ?? [
            'filename' => $batch->filename,
            'user_id' => $batch->user_id,
            'type' => $batch->type,
            'total_rows' => $batch->total_rows,
            'validation' => $validation,
        ];
        $cached['status'] = $batch->status;
        $cached['processed_count'] = $batch->processed_valid_count;
        $cached['progress'] = $batch->status === 'completed'
            ? 100
            : ($batch->valid_count === 0 ? 100 : (int) (($batch->processed_valid_count / $batch->valid_count) * 100));
        $cached['row_issues'] = $batch->row_issues ?? [];

        if ($batch->status === 'completed') {
            $cached['result'] = [
                'inserted' => $batch->inserted_count,
                'skipped' => $batch->skipped_count,
                'failed' => $batch->failed_count,
            ];
        }

        Cache::put($cacheKey, $cached, now()->addMinutes($batch->status === 'completed' ? 10 : UploadImportBatchAction::CACHE_TTL_MINUTES));
    }

    /** Memulihkan cleanup file dan cache jika worker crash setelah commit status completed. */
    private function recoverCompletedSideEffects(ImportBatch $batch): void
    {
        $this->cleanupBatch($batch->id, $batch->filename);
        $this->projectCache($batch);
    }

    /**
     * Menjalankan satu baris tervalidasi dan mengembalikan outcome aktual untuk rekonsiliasi counter.
     *
     * @param  array<string, mixed>  $row
     * @return array{status: 'inserted', employee_id: string}|array{status: 'skipped', issue?: array<string, mixed>}|array{status: 'failed', issue: array<string, mixed>}
     *
     * Selain NIP, keunikan email_pribadi juga diperiksa secara atomik karena skema produksi
     * memiliki indeks unik case-insensitive pada kolom tersebut. Race antara validasi dan
     * eksekusi (pegawai lain mendaftar email yang sama setelah preview valid) tetap diklasifikasikan
     * sebagai error sesuai aturan bahwa satu email tidak boleh menunjuk pegawai berbeda.
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

            // Validasi dan eksekusi terpisah waktu; NIP dan email dapat diklaim pegawai lain
            // setelah preview dinyatakan valid. Pre-check atomik mencegah insert yang tidak perlu
            // sebelum constraint database melempar QueryException.
            if (! empty($data['nip']) && Employee::withTrashed()->where('nip', $data['nip'])->exists()) {
                return $this->duplicateNipOutcome($row);
            }

            if (! empty($data['email_pribadi'])
                && Employee::withTrashed()
                    ->whereRaw('LOWER(email_pribadi) = ?', [strtolower((string) $data['email_pribadi'])])
                    ->exists()
            ) {
                return $this->duplicateEmailOutcome($row);
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
                // Hanya tabrakan constraint NIP atau email_pribadi yang merupakan outcome skip;
                // pelanggaran constraint lain harus tetap dilempar agar masalah integritas data
                // tidak tersamarkan dan batch masuk antrian retry.
                if ($this->isDuplicateNipViolation($exception)) {
                    return $this->duplicateNipOutcome($row);
                }

                if ($this->isDuplicateEmailViolation($exception)) {
                    return $this->duplicateEmailOutcome($row);
                }

                throw $exception;
            }

            return ['status' => 'inserted', 'employee_id' => $employee->id];
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
     * Memastikan unique violation berasal dari constraint email_pribadi (functional index
     * case-insensitive), bukan dari constraint lain yang mungkin ada di tabel pegawai.
     */
    private function isDuplicateEmailViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverDiagnostic = (string) ($exception->errorInfo[2] ?? '');

        if ($sqlState === '23505') {
            preg_match('/unique constraint ["\']([^"\']+)["\']/i', $driverDiagnostic, $matches);

            return ($matches[1] ?? null) === 'employees_email_pribadi_unique';
        }

        return $sqlState === '23000'
            && preg_match(
                '/unique constraint failed:\s*(?:employees\.email_pribadi\b|index ["\']employees_email_pribadi_unique["\'])/i',
                $driverDiagnostic,
            ) === 1;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{status: 'failed', issue: array<string, mixed>}
     */
    private function duplicateEmailOutcome(array $row): array
    {
        return [
            'status' => 'failed',
            'issue' => [
                'row' => $row['row'] ?? null,
                'nama' => $row['nama'] ?? '-',
                'kategori' => 'gagal',
                'errors' => [
                    'Email' => ['Email sudah terdaftar saat proses import dijalankan.'],
                ],
            ],
        ];
    }

    /** Menghitung jeda minimum sebelum delivery boleh mencoba CAS lease kembali. */
    private function retryAfterSeconds(ImportBatch $batch): int
    {
        $leaseExpiresAt = $batch->lease_expires_at;

        if ($batch->status !== 'processing' || ! $leaseExpiresAt instanceof CarbonInterface) {
            return 1;
        }

        return max(1, $leaseExpiresAt->getTimestamp() - now()->getTimestamp());
    }

    private function cleanupBatch(string $batchId, string $filename): void
    {
        $storedName = $batchId.'_'.$filename;
        if (Storage::disk('local')->exists(UploadImportBatchAction::STORAGE_DIR.'/'.$storedName)) {
            Storage::disk('local')->delete(UploadImportBatchAction::STORAGE_DIR.'/'.$storedName);
        }
    }
}
