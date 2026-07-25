<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reports\ExportLeaveReportAction;
use App\Actions\Reports\ExportRankHistoryPdfAction;
use App\Actions\Reports\ExportRankHistoryReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\LeaveReportFilterRequest;
use App\Http\Requests\Reports\RankHistoryReportFilterRequest;
use Illuminate\Pagination\LengthAwarePaginator;

class PimpinanReportController extends Controller
{
    public function index()
    {
        return redirect()->route('pimpinan.laporan.pegawai');
    }

    public function leaves(LeaveReportFilterRequest $request, ExportLeaveReportAction $action)
    {
        $filters = $request->validated();
        $perPage = request('per_page', 10);
        $page = request('page', 1);

        $allRows = $action->rows($filters);
        $previewData = new LengthAwarePaginator(
            $allRows->forPage($page, $perPage)->values(),
            $allRows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('pimpinan.laporan.cuti', [
            'previewData' => $previewData,
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
        $perPage = request('per_page', 10);
        $page = request('page', 1);

        $allRows = $action->rows($filters);
        $previewData = new LengthAwarePaginator(
            $allRows->forPage($page, $perPage)->values(),
            $allRows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        return view('pimpinan.laporan.kepangkatan', [
            'previewData' => $previewData,
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
