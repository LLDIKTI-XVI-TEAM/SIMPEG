<?php

namespace App\Http\Controllers;

use App\Actions\Employees\GenerateImportTemplateAction;
use App\Actions\Employees\ImportEmployeesAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Actions\Employees\ValidateImportBatchAction;
use App\Http\Requests\ImportEmployeesRequest;
use App\Jobs\ImportEmployeeBatchJob;
use App\Support\EmployeeImport\ImportTemplateWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeImportController extends Controller
{
    /**
     * Handle the legacy /api/v1/pegawai/import request.
     */
    public function store(ImportEmployeesRequest $request, ImportEmployeesAction $action): JsonResponse|RedirectResponse
    {
        $summary = $action->execute($request);

        if ($request->expectsJson()) {
            return response()->json($summary, $summary['failed'] > 0 ? 422 : 200);
        }

        if ($summary['failed'] > 0) {
            return back()
                ->withErrors(['file' => $summary['message']])
                ->with('import_summary', $summary);
        }

        return back()->with('import_summary', $summary);
    }

    /**
     * Handle the import wizard upload step.
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
     * Handle the import wizard preview step.
     */
    public function preview(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        return response()->json([
            'batch_id' => $batchId,
            'filename' => $batch['filename'],
            'type' => $batch['type'] ?? 'utama',
            'type_label' => $batch['type_label'] ?? UploadImportBatchAction::TEMPLATE_LABELS['utama'],
            'total_rows' => $batch['total_rows'],
            'headers' => $batch['headers'],
            'rows' => $batch['rows'],
        ]);
    }

    /**
     * Handle the import wizard validate step.
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
     * Handle the import wizard execute step (Queue the job).
     */
    public function execute(Request $request, string $batchId): JsonResponse
    {
        $batch = $this->getBatchOrFail($batchId, $request);

        if ($batch['validation'] === null) {
            return response()->json([
                'message' => 'Data belum divalidasi. Jalankan validasi terlebih dahulu.',
            ], 422);
        }

        // Set status to queued in cache
        $batch['status'] = 'queued';
        $batch['progress'] = 0;
        $batch['processed_count'] = 0;
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $batch, now()->addMinutes(UploadImportBatchAction::CACHE_TTL_MINUTES));

        // Dispatch background job
        ImportEmployeeBatchJob::dispatch(
            $batchId,
            $request->user()?->id,
            $request->ip(),
            $request->userAgent()
        );

        return response()->json([
            'status' => 'queued',
            'message' => 'Proses impor telah dimasukkan ke dalam antrean. Anda dapat meninggalkan halaman ini.',
        ]);
    }

    /**
     * Handle checking the import queue status/progress.
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

        return $writer->stream($type, $definition['headers'], $definition['example'], $format);
    }

    /**
     * Retrieve the import batch from cache or fail with HTTP exception.
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
