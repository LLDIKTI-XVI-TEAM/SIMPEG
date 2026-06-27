<?php

namespace App\Actions\Employees;

use App\Actions\Histories\CreateKgbHistoryAction;
use App\Actions\Histories\CreatePositionHistoryAction;
use App\Actions\Histories\CreateRankHistoryAction;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExecuteImportBatchAction
{
    public function __construct(
        private readonly CreateRankHistoryAction $createRankHistoryAction,
        private readonly CreatePositionHistoryAction $createPositionHistoryAction,
        private readonly CreateKgbHistoryAction $createKgbHistoryAction
    ) {}

    /**
     * Execute the validated batch.
     *
     * @param  string  $batchId
     * @param  User|null  $user
     * @param  Request  $request
     * @return array
     *
     * @throws ValidationException
     */
    public function execute(string $batchId, ?User $user, Request $request): array
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
        $processedCount = 0;

        if ($validRows !== []) {
            DB::transaction(function () use ($validRows, $type, $request, &$processedCount): void {
                foreach ($validRows as $result) {
                    $this->executeValidatedRow($type, $result['validated_data'], $request);
                    $processedCount++;
                }
            });
        }

        AuditService::log('IMPORT', 'Employee', null, null, [
            'template_type' => $type,
            'template_label' => UploadImportBatchAction::TEMPLATE_LABELS[$type] ?? UploadImportBatchAction::TEMPLATE_LABELS['utama'],
            'total_inserted' => $type === 'utama' ? $processedCount : 0,
            'total_processed' => $processedCount,
            'total_skipped' => $batch['validation']['skip_count'],
            'total_failed' => $batch['validation']['error_count'],
            'filename' => $batch['filename'],
        ], $request);

        $this->cleanupBatch($batchId, $batch['filename']);

        return [
            'message' => 'Import selesai.',
            'inserted' => $processedCount,
            'processed' => $processedCount,
            'skipped' => $batch['validation']['skip_count'],
            'failed' => $batch['validation']['error_count'],
        ];
    }

    private function executeValidatedRow(string $type, array $data, Request $request): void
    {
        if ($type === 'utama') {
            Employee::create($data + [
                'status_aktif' => 'Aktif',
                'profil_status' => 'belum_lengkap',
                'is_kinerja_baik' => true,
            ]);

            return;
        }

        $employee = Employee::findOrFail($data['employee_id']);
        unset($data['employee_id']);

        match ($type) {
            'pelengkap' => $employee->update($data),
            'kepangkatan' => $this->createRankHistoryAction->execute($employee, $data, $request),
            'jabatan' => $this->createPositionHistoryAction->execute($employee, $data, $request),
            'kgb' => $this->createKgbHistoryAction->execute($employee, $data, $request),
            default => null,
        };
    }

    private function cleanupBatch(string $batchId, string $filename): void
    {
        Cache::forget(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        $storedName = $batchId.'_'.$filename;
        if (Storage::disk('local')->exists(UploadImportBatchAction::STORAGE_DIR.'/'.$storedName)) {
            Storage::disk('local')->delete(UploadImportBatchAction::STORAGE_DIR.'/'.$storedName);
        }
    }
}
