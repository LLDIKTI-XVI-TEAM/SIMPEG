<?php

namespace App\Actions\Laporan;

use App\Http\Requests\Laporan\CustomEmployeeExportRequest;
use App\Services\Laporan\EmployeeExportDataService;
use App\Services\Laporan\EmployeeExportSpreadsheetService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportCustomPegawaiExcelAction
{
    public function __construct(
        private readonly EmployeeExportDataService $employeeExportData,
        private readonly EmployeeExportSpreadsheetService $spreadsheet,
    ) {}

    /** @param array<string, mixed> $validated */
    public function execute(array $validated): StreamedResponse
    {
        /** @var list<string> $requestedColumns */
        $requestedColumns = $validated['columns'];

        // Pertahankan urutan kolom sesuai permintaan pengguna (bukan urutan baku).
        $columns = [];
        foreach ($requestedColumns as $key) {
            if (isset(CustomEmployeeExportRequest::ALLOWED_COLUMNS[$key])) {
                $columns[$key] = CustomEmployeeExportRequest::ALLOWED_COLUMNS[$key];
            }
        }

        $workbook = $this->spreadsheet->make(
            $this->employeeExportData->rows($validated, defaultToActive: false),
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
