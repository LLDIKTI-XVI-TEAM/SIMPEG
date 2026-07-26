<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Laporan\PimpinanCustomEmployeeExportAction;
use App\Actions\Reports\ExportFixedEmployeePdfAction;
use App\Actions\Reports\ExportLeaveReportAction;
use App\Actions\Reports\ExportRankHistoryPdfAction;
use App\Actions\Reports\ExportRankHistoryReportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laporan\ExportPegawaiRequest;
use App\Http\Requests\Reports\LeaveReportFilterRequest;
use App\Http\Requests\Reports\RankHistoryReportFilterRequest;
use App\Models\RefJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use App\Services\Laporan\EmployeeExportDataService;
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

    public function fixedEmployeeReport(ExportPegawaiRequest $request, EmployeeExportDataService $exportData)
    {
        $filters = $request->validated();
        $perPage = (int) request('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $page = max(1, (int) request('page', 1));

        $allRows = $exportData->rows($filters);
        $previewData = new LengthAwarePaginator(
            $allRows->forPage($page, $perPage)->values(),
            $allRows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $unitKerjaOptions = RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']);
        $jenisPegawaiOptions = RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']);
        $jabatanOptions = RefJabatan::query()->orderBy('nama')->get(['id', 'nama']);

        return view('pimpinan.laporan.nominatif', [
            'filters' => $filters,
            'previewData' => $previewData,
            'unitKerjaOptions' => $unitKerjaOptions,
            'jenisPegawaiOptions' => $jenisPegawaiOptions,
            'jabatanOptions' => $jabatanOptions,
        ]);
    }

    public function exportFixedEmployeeReportExcel(ExportPegawaiRequest $request, PimpinanCustomEmployeeExportAction $action)
    {
        $validated = $request->validated();
        // Paksa hanya kolom aman PRD (NIP, Nama, Golongan, Jabatan, Unit Kerja, Jenis Pegawai)
        $validated['columns'] = ['nip', 'nama', 'golongan', 'jabatan', 'unit', 'jenis'];

        return $action->execute($validated);
    }

    public function exportFixedEmployeeReportPdf(ExportPegawaiRequest $request, EmployeeExportDataService $exportData, ExportFixedEmployeePdfAction $pdf)
    {
        $filters = $request->validated();
        $rows = $exportData->rows($filters);

        return $pdf->execute($rows);
    }
}
