<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ExportCutiExcelAction;
use App\Actions\Cuti\ExportCutiPdfAction;
use App\Actions\Cuti\ShowCutiReportPreviewAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ListCutiRekapRequest;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

class CutiReportController extends Controller
{
    public function preview(ListCutiRekapRequest $request, ShowCutiReportPreviewAction $action): View
    {
        return view('admin.cuti.laporan', $action->execute($request->validated()));
    }

    public function pdf(ListCutiRekapRequest $request, ExportCutiPdfAction $action): Response
    {
        return $action->execute($request->validated());
    }

    public function excel(ListCutiRekapRequest $request, ExportCutiExcelAction $action): Response
    {
        return $action->execute($request->validated());
    }
}
