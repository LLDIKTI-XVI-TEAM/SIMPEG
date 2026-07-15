<?php

namespace App\Actions\Laporan;

use App\Http\Requests\Laporan\CustomEmployeeExportRequest;
use App\Services\Laporan\EmployeeExportDataService;
use App\Services\Laporan\EmployeeExportSpreadsheetService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportCustomPegawaiExcelAction
{
    /** @var list<string> */
    private const COLUMN_ORDER = [
        'nip',
        'nama',
        'golongan',
        'jabatan',
        'unit',
        'jenis',
        'status',
        'pendidikan',
        'tanggal_pensiun',
    ];

    public function __construct(
        private readonly EmployeeExportDataService $employeeExportData,
        private readonly EmployeeExportSpreadsheetService $spreadsheet,
    ) {}

    /** @param array<string, mixed> $validated */
    public function execute(array $validated): StreamedResponse
    {
        /** @var list<string> $requestedColumns */
        $requestedColumns = $validated['columns'];
        $selectedColumns = array_fill_keys($requestedColumns, true);
        $columns = [];

        foreach (self::COLUMN_ORDER as $column) {
            if (isset($selectedColumns[$column])) {
                $columns[$column] = CustomEmployeeExportRequest::ALLOWED_COLUMNS[$column];
            }
        }

        $workbook = $this->spreadsheet->make(
            $this->employeeExportData->rows($validated),
            $columns,
            'Nominatif Pegawai Custom',
        );
        $filename = 'Daftar_Nominatif_Pegawai_Custom_'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($workbook): void {
            try {
                (new Xlsx($workbook))->save('php://output');
            } finally {
                $workbook->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
