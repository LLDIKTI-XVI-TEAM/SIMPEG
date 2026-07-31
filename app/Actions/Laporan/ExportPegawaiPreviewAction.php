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
        // Range baris diterapkan oleh Alpine pada pratinjau. Kirim data yang
        // belum dipotong agar range dari initialFilters tidak diterapkan dua kali.
        $previewFilters = $filters;
        unset($previewFilters['row_start'], $previewFilters['row_end']);

        return view('admin.laporan.export-pegawai', [
            'pegawai' => $this->employeeExportData->rows($previewFilters, defaultToActive: false)->all(),
            'filterOptions' => $this->employeeExportData->filterOptions(),
            'initialFilters' => [
                'search' => (string) ($filters['search'] ?? ''),
                'unit' => (string) ($filters['unit'] ?? ''),
                'golongan' => (string) ($filters['golongan'] ?? ''),
                'jenis' => (string) ($filters['jenis'] ?? ''),
                'status' => (string) (($filters['status'] ?? null) ?: 'Aktif'),
                'jabatan' => (string) ($filters['jabatan'] ?? ''),
                'pensiun_dari' => (string) ($filters['pensiun_dari'] ?? ''),
                'pensiun_sampai' => (string) ($filters['pensiun_sampai'] ?? ''),
                'sort' => (string) ($filters['sort'] ?? 'nama'),
                'sort_dir' => (string) ($filters['sort_dir'] ?? 'asc'),
                'prefix_field' => (string) ($filters['prefix_field'] ?? 'nama'),
                'prefix_value' => (string) ($filters['prefix_value'] ?? ''),
                'row_start' => (int) ($filters['row_start'] ?? 1),
                'row_end' => isset($filters['row_end']) ? (int) $filters['row_end'] : '',
            ],
            'title' => 'Laporan - Export Pegawai',
        ]);
    }
}
