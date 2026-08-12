<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\DownloadImportReportAction;
use App\Actions\Employees\GenerateImportTemplateAction;
use App\Actions\Employees\SaveImportMappingAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Actions\Employees\ValidateImportBatchAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Import\ImportEmployeesRequest;
use App\Http\Requests\Import\SaveImportMappingRequest;
use App\Jobs\ImportEmployeeBatchJob;
use App\Models\ImportBatch;
use App\Support\EmployeeImport\ImportColumnMapping;
use App\Support\EmployeeImport\ImportTemplateWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeImportController extends Controller
{
    /**
     * Menangani langkah unggah pada wizard impor pegawai.
     */
    public function upload(ImportEmployeesRequest $request, UploadImportBatchAction $action): JsonResponse
    {
        $result = $action->execute(
            $request->file('file'),
            $request->input('type'),
            $request->user()
        );

        return response()->json($result);
    }

    /**
     * Menangani langkah pratinjau pada wizard impor pegawai.
     */
    public function preview(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        $rows = array_slice($batch['rows'], 0, 10);
        if ($request->has('row')) {
            $rowNumber = (int) $request->validate([
                'row' => ['required', 'integer', 'min:2'],
            ])['row'];
            $row = collect($batch['rows'])->first(
                fn (array $candidate): bool => (int) ($candidate['row'] ?? 0) === $rowNumber,
            );

            abort_if($row === null, 404, 'Baris import tidak ditemukan pada batch ini.');
            $rows = [$row];
        }

        // Respons preview dibatasi server ke 10 baris pertama sesuai kontrak wizard;
        // seluruh baris tetap tersimpan pada batch untuk validasi dan eksekusi.
        // Mapping aktif ikut dikembalikan agar UI menampilkan state server,
        // bukan tebakan client.
        return response()->json([
            'batch_id' => $batchId,
            'filename' => $batch['filename'],
            'type' => $batch['type'] ?? 'utama',
            'type_label' => $batch['type_label'] ?? UploadImportBatchAction::TEMPLATE_LABELS['utama'],
            'total_rows' => $batch['total_rows'],
            'headers' => $batch['headers'],
            'rows' => $rows,
            'mapping' => $batch['mapping'] ?? ImportColumnMapping::autoMap($batch['headers']),
            'warnings' => $batch['warnings'] ?? ImportColumnMapping::warnings($batch['mapping'] ?? []),
            'required_targets' => ImportColumnMapping::requiredTargets(),
        ]);
    }

    /**
     * Menyimpan pemetaan kolom pilihan admin pada batch import.
     * Controller hanya meneruskan request tervalidasi ke Action.
     */
    public function saveMapping(SaveImportMappingRequest $request, string $batchId, SaveImportMappingAction $action): JsonResponse
    {
        return response()->json($action->execute($batchId, $request->validated()['mapping'], $request->user()));
    }

    /**
     * Menangani langkah validasi pada wizard impor pegawai.
     */
    public function validate(Request $request, string $batchId, ValidateImportBatchAction $action): JsonResponse
    {
        $updatedRows = $request->has('rows') ? $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.row' => ['required', 'integer', 'min:2'],
            'rows.*.data' => ['required', 'array'],
        ])['rows'] : null;

        $result = $action->execute($batchId, $updatedRows, $request->user());

        return response()->json($result);
    }

    /**
     * Menangani eksekusi impor dengan memasukkan job ke antrean.
     */
    public function execute(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        if ($batch['validation'] === null) {
            return response()->json([
                'message' => 'Data belum divalidasi. Jalankan validasi terlebih dahulu.',
            ], 422);
        }

        $claimed = Cache::lock("import-batch-dispatch:{$batchId}", 10)->block(3, function () use ($batchId, $batch, $request): bool {
            return DB::transaction(function () use ($batchId, $batch, $request): bool {
                $model = ImportBatch::query()->lockForUpdate()->find($batchId);

                if ($model === null) {
                    $model = ImportBatch::create([
                        'id' => $batchId,
                        'user_id' => $request->user()?->id,
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
            return response()->json([
                'status' => ImportBatch::find($batchId)?->status ?? 'queued',
                'message' => 'Batch import sudah sedang diproses atau telah selesai.',
            ]);
        }

        // Status cache diubah setelah claim database berhasil agar UI segera menampilkan antrean.
        $batch['status'] = 'queued';
        $batch['progress'] = 0;
        $batch['processed_count'] = 0;
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        // Job menyimpan konteks user/IP untuk audit impor pegawai.
        try {
            ImportEmployeeBatchJob::dispatch(
                $batchId,
                $request->user()?->id,
                $request->ip(),
                $request->userAgent()
            );
        } catch (\Throwable $exception) {
            ImportBatch::whereKey($batchId)->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'finished_at' => now(),
            ]);

            $batch['status'] = 'failed';
            $batch['error_message'] = $exception->getMessage();
            Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

            return response()->json([
                'status' => 'failed',
                'message' => 'Batch import gagal dimasukkan ke antrean. Silakan coba lagi.',
            ], 503);
        }

        return response()->json([
            'status' => 'queued',
            'message' => 'Proses impor telah dimasukkan ke dalam antrean. Anda dapat meninggalkan halaman ini.',
        ]);
    }

    /**
     * Mengembalikan progres antrean impor untuk polling UI.
     */
    public function status(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        return response()->json([
            'batch_id' => $batchId,
            'status' => $batch['status'] ?? 'pending',
            'progress' => $batch['progress'] ?? 0,
            'processed_count' => $batch['processed_count'] ?? 0,
            'total_rows' => $batch['total_rows'] ?? 0,
            'error_message' => $batch['error_message'] ?? null,
            'result' => $batch['result'] ?? null,
        ]);
    }

    /**
     * Unduh laporan hasil import dari record permanen (bukan cache/state browser).
     */
    public function report(Request $request, string $batchId, DownloadImportReportAction $action): StreamedResponse
    {
        $batch = ImportBatch::find($batchId);

        if ($batch === null) {
            abort(404, 'Laporan import tidak ditemukan.');
        }

        if ($batch->user_id !== null && $batch->user_id !== $request->user()?->id) {
            abort(403, 'Anda tidak memiliki akses ke laporan import ini.');
        }

        return $action->execute($batch);
    }

    /**
     * Hasilkan dan unduh template import untuk satu tipe.
     * Controller hanya validasi input dan koordinasi Action + Writer (thin adapter).
     */
    public function template(string $type, GenerateImportTemplateAction $action, ImportTemplateWriter $writer): StreamedResponse
    {
        try {
            $definition = $action->execute($type);
        } catch (InvalidArgumentException) {
            abort(404, 'Tipe template tidak dikenal.');
        }

        $format = strtolower((string) request('format', 'xlsx'));
        $format = in_array($format, ['xlsx', 'csv'], true) ? $format : 'xlsx';

        return $writer->stream($type, $definition['headers'], $definition['examples'], $format);
    }

    /**
     * Mengambil batch impor dari cache dan menjaga akses hanya untuk pemilik batch.
     */
    private function getBatchOrFail(string $batchId, Request $request): array
    {
        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        if ($batch === null) {
            abort(404, 'Batch import tidak ditemukan atau sudah kedaluwarsa. Silakan upload ulang.');
        }

        if ($batch['user_id'] !== null && $batch['user_id'] !== $request->user()?->id) {
            abort(403, 'Anda tidak memiliki akses ke batch import ini.');
        }

        return $batch;
    }
}
