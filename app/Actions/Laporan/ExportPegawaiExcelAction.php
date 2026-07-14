<?php

namespace App\Actions\Laporan;

use App\Services\Laporan\EmployeeExportDataService;
use App\Services\Laporan\EmployeeExportSpreadsheetService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportPegawaiExcelAction
{
    /** @var array<string, string> */
    private const COLUMNS = [
        'no' => 'No',
        'nip' => 'NIP',
        'nama' => 'Nama',
        'golongan' => 'Golongan',
        'jabatan' => 'Jabatan',
        'unit' => 'Unit Kerja',
        'jenis' => 'Jenis Pegawai',
        'status' => 'Status',
    ];

    public function __construct(
        private readonly EmployeeExportDataService $employeeExportData,
        private readonly EmployeeExportSpreadsheetService $spreadsheet,
    ) {}

    /** @param array<string, mixed> $filters */
    public function execute(array $filters): StreamedResponse
    {
        $rows = $this->employeeExportData->rows($filters)->map(
            fn (array $row, int $index): array => ['no' => (string) ($index + 1), ...$row]
        );
        $workbook = $this->spreadsheet->make($rows, self::COLUMNS, 'Daftar Nominatif Pegawai');
        $filename = 'Daftar_Pegawai_LLDIKTI_XVI_'.now()->format('Ymd').'.xlsx';

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
