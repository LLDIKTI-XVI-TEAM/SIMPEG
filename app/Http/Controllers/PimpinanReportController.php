<?php

namespace App\Http\Controllers;

use App\Actions\Reports\ExportCustomEmployeeReportAction;
use App\Actions\Reports\ExportLeaveReportAction;
use App\Actions\Reports\ExportRankHistoryPdfAction;
use App\Actions\Reports\ExportRankHistoryReportAction;
use App\Http\Requests\Reports\ExportCustomEmployeeReportRequest;
use App\Http\Requests\Reports\LeaveReportFilterRequest;
use App\Http\Requests\Reports\RankHistoryReportFilterRequest;

class PimpinanReportController extends Controller
{
    public function index()
    {
        return view('pimpinan.laporan.index');
    }

    public function employees(ExportCustomEmployeeReportRequest $request, ExportCustomEmployeeReportAction $action)
    {
        $payload = $request->validated();
        $filters = array_diff_key($payload, ['columns' => true]);

        return view('pimpinan.laporan.pegawai', [
            'previewData' => $action->preview($filters),
            'filterOptions' => $action->filterOptions(),
            'filters' => $filters,
            'selectedColumns' => $payload['columns'] ?? null,
        ]);
    }

    public function customEmployees(ExportCustomEmployeeReportRequest $request, ExportCustomEmployeeReportAction $action)
    {
        return $action->execute($request->validated());
    }

    public function leaves(LeaveReportFilterRequest $request, ExportLeaveReportAction $action)
    {
        $filters = $request->validated();

        return view('pimpinan.laporan.cuti', [
            'previewData' => $action->preview($filters),
            'filterOptions' => $action->filterOptions(),
            'filters' => $filters,
        ]);
    }

    public function exportLeaves(LeaveReportFilterRequest $request, ExportLeaveReportAction $action)
    {
        return $action->execute($request->validated());
    }

    public function rankHistories(RankHistoryReportFilterRequest $request, ExportRankHistoryReportAction $action)
    {
        $filters = $request->validated();

        return view('pimpinan.laporan.kepangkatan', [
            'previewData' => $action->preview($filters),
            'filterOptions' => $action->filterOptions(),
            'filters' => $filters,
        ]);
    }

    public function exportRankHistoriesExcel(RankHistoryReportFilterRequest $request, ExportRankHistoryReportAction $action)
    {
        return $action->executeExcel($request->validated());
    }

    public function exportRankHistoriesPdf(
        RankHistoryReportFilterRequest $request,
        ExportRankHistoryReportAction $report,
        ExportRankHistoryPdfAction $pdf,
    ) {
        return $pdf->execute($report->rows($request->validated()));
    }
}
