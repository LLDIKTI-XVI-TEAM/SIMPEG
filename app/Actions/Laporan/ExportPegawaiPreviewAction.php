<?php

namespace App\Actions\Laporan;

use App\Services\Laporan\EmployeeExportDataService;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ExportPegawaiPreviewAction
{
    public function __construct(private readonly EmployeeExportDataService $employeeExportData) {}

    /** @param array<string, mixed> $filters */
    public function execute(array $filters): View
    {
        return view('admin.laporan.export-pegawai', [
            'pegawai' => $this->previewRows($filters)->all(),
            'filterOptions' => $this->employeeExportData->filterOptions(),
            'initialFilters' => [
                'search' => (string) ($filters['search'] ?? ''),
                'unit' => (string) ($filters['unit'] ?? ''),
                'golongan' => (string) ($filters['golongan'] ?? ''),
                'jenis' => (string) ($filters['jenis'] ?? ''),
                'status' => array_key_exists('status', $filters) ? (string) $filters['status'] : 'Aktif',
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

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, string>>
     */
    public function previewRows(array $filters): Collection
    {
        if (! array_key_exists('status', $filters)) {
            $filters['status'] = 'Aktif';
        }

        return $this->employeeExportData->rows($filters, defaultToActive: false);
    }
}
