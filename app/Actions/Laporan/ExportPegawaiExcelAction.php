<?php

namespace App\Actions\Laporan;

use App\Services\Laporan\EmployeeExportDataService;
use App\Services\Laporan\EmployeeExportSpreadsheetService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportPegawaiExcelAction
{
    /**
     * Kolom default apabila user tidak menentukan kolom sama sekali.
     *
     * @var array<string, string>
     */
    private const DEFAULT_COLUMNS = [
        'no' => 'No',
        'nip' => 'NIP',
        'nama' => 'Nama',
        'golongan' => 'Golongan',
        'jabatan' => 'Jabatan',
        'unit' => 'Unit Kerja',
        'jenis' => 'Jenis Pegawai',
        'status' => 'Status',
    ];

    /**
     * Semua kolom yang boleh dipilih user (superset dari DEFAULT_COLUMNS).
     *
     * @var array<string, string>
     */
    private const ALLOWED_COLUMNS = [
        'no' => 'No',
        'nip' => 'NIP',
        'nama' => 'Nama',
        'golongan' => 'Golongan',
        'jabatan' => 'Jabatan',
        'unit' => 'Unit Kerja',
        'jenis' => 'Jenis Pegawai',
        'status' => 'Status',
        'pendidikan' => 'Pendidikan Terakhir',
        'tanggal_pensiun' => 'Tgl. Pensiun',
    ];

    public function __construct(
        private readonly EmployeeExportDataService $employeeExportData,
        private readonly EmployeeExportSpreadsheetService $spreadsheet,
    ) {}

    /** @param array<string, mixed> $filters */
    public function execute(array $filters): StreamedResponse
    {
        // Bangun daftar kolom: ikuti pilihan user, atau gunakan kolom default.
        /** @var list<string> $requested */
        $requested = (array) ($filters['columns'] ?? []);
        $columns = $this->resolveColumns($requested);

        $needsRowNumber = isset($columns['no']);

        $rows = $this->employeeExportData->rows($filters, defaultToActive: false)->map(
            function (array $row, int $index) use ($needsRowNumber): array {
                if ($needsRowNumber) {
                    return ['no' => (string) ($index + 1), ...$row];
                }

                return $row;
            }
        );

        $workbook = $this->spreadsheet->make($rows, $columns, 'Daftar Nominatif Pegawai');
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

    /**
     * Jika user mengirim kolom, pakai urutan permintaan user.
     * Jika tidak ada pilihan, gunakan kolom default.
     *
     * @param  list<string>  $requested
     * @return array<string, string>
     */
    private function resolveColumns(array $requested): array
    {
        if (empty($requested)) {
            return self::DEFAULT_COLUMNS;
        }

        $columns = [];
        foreach ($requested as $key) {
            if (isset(self::ALLOWED_COLUMNS[$key])) {
                $columns[$key] = self::ALLOWED_COLUMNS[$key];
            }
        }

        // Jika semua key ditolak (tidak valid), fallback ke default.
        return $columns ?: self::DEFAULT_COLUMNS;
    }
}
