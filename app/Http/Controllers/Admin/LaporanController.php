<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Laporan\ExportCustomPegawaiExcelAction;
use App\Actions\Laporan\ExportPegawaiExcelAction;
use App\Actions\Laporan\ExportPegawaiPdfAction;
use App\Actions\Laporan\ExportPegawaiPreviewAction;
use App\Actions\Reports\ExportFixedEmployeePdfAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laporan\CustomEmployeeExportRequest;
use App\Http\Requests\Laporan\ExportPegawaiRequest;
use App\Services\Laporan\EmployeeExportDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanController extends Controller
{
    public function exportPegawai(ExportPegawaiRequest $request, ExportPegawaiPreviewAction $action): View
    {
        return $action->execute($request->validated());
    }

    public function exportPegawaiPreview(ExportPegawaiRequest $request, ExportPegawaiPreviewAction $action): JsonResponse
    {
        return response()->json([
            'pegawai' => $action->previewRows($request->validated())->values()->all(),
        ]);
    }

    public function exportPegawaiExcel(ExportPegawaiRequest $request, ExportPegawaiExcelAction $action): StreamedResponse
    {
        return $action->execute($request->validated());
    }

    public function exportPegawaiPdf(ExportPegawaiRequest $request, ExportPegawaiPdfAction $action): Response|StreamedResponse|RedirectResponse
    {
        return $action->execute($request->validated());
    }

    public function exportPegawaiNominatifPdf(
        ExportPegawaiRequest $request,
        EmployeeExportDataService $exportData,
        ExportFixedEmployeePdfAction $action,
    ): StreamedResponse {
        $filters = $request->validated();
        if (! array_key_exists('status', $filters) && ! array_key_exists('status_pegawai_id', $filters)) {
            $filters['status'] = 'Aktif';
        }

        $rows = $exportData->rows($filters, defaultToActive: false);

        return $action->execute($rows);
    }

    public function exportPegawaiCustom(CustomEmployeeExportRequest $request, ExportCustomPegawaiExcelAction $action): StreamedResponse
    {
        return $action->execute($request->validated());
    }
}
