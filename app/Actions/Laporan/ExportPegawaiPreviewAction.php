<?php

namespace App\Actions\Laporan;

use App\Services\Laporan\EmployeeExportDataService;
use Illuminate\View\View;

class ExportPegawaiPreviewAction
{
    public function __construct(private readonly EmployeeExportDataService $employeeExportData) {}

    /** @param array<string, mixed> $filters */
    public function execute(array $filters): View
    {
        return view('admin.laporan.export-pegawai', [
            'pegawai' => $this->employeeExportData->rows($filters, defaultToActive: false)->all(),
            'filterOptions' => $this->employeeExportData->filterOptions(),
            'initialFilters' => [
                'search' => (string) ($filters['search'] ?? ''),
                'unit' => (string) ($filters['unit'] ?? ''),
                'golongan' => (string) ($filters['golongan'] ?? ''),
                'jenis' => (string) ($filters['jenis'] ?? ''),
                'status' => (string) (($filters['status'] ?? null) ?: 'Aktif'),
                'sort' => (string) ($filters['sort'] ?? 'nama'),
            ],
            'title' => 'Laporan - Export Pegawai',
        ]);
    }
}
