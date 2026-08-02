<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Laporan\ExportCustomPegawaiExcelAction;
use App\Actions\Laporan\ExportPegawaiExcelAction;
use App\Actions\Laporan\ExportPegawaiPreviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laporan\CustomEmployeeExportRequest;
use App\Http\Requests\Laporan\ExportPegawaiRequest;
use Illuminate\Http\JsonResponse;
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

    public function exportPegawaiCustom(CustomEmployeeExportRequest $request, ExportCustomPegawaiExcelAction $action): StreamedResponse
    {
        return $action->execute($request->validated());
    }
}
