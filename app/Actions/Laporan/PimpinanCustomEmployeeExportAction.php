<?php

namespace App\Actions\Laporan;

use App\Services\Laporan\EmployeeExportDataService;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PimpinanCustomEmployeeExportAction
{
    /**
     * Kolom yang tersedia untuk laporan pimpinan.
     * Label 'nama' menggunakan 'Nama Pegawai' agar lebih eksplisit.
     *
     * @var array<string, string>
     */
    public const ALLOWED_COLUMNS = [
        'nip'             => 'NIP',
        'nama'            => 'Nama Pegawai',
        'golongan'        => 'Golongan',
        'jabatan'         => 'Jabatan',
        'unit'            => 'Unit Kerja',
        'jenis'           => 'Jenis Pegawai',
        'status'          => 'Status',
        'pendidikan'      => 'Pendidikan Terakhir',
        'tanggal_pensiun' => 'Tanggal Pensiun',
    ];

    /** @var array<string, int> */
    private const COLUMN_WIDTHS = [
        'nip'             => 24,
        'nama'            => 32,
        'golongan'        => 12,
        'jabatan'         => 38,
        'unit'            => 24,
        'jenis'           => 18,
        'status'          => 16,
        'pendidikan'      => 22,
        'tanggal_pensiun' => 18,
    ];

    public function __construct(
        private readonly EmployeeExportDataService $exportData,
    ) {}

    /** @param array<string, mixed> $validated */
    public function execute(array $validated): StreamedResponse
    {
        /** @var list<string> $requestedColumns */
        $requestedColumns = $validated['columns'];

        // Pertahankan urutan kolom sesuai permintaan pengguna (bukan urutan baku).
        $columns = [];
        foreach ($requestedColumns as $key) {
            if (isset(self::ALLOWED_COLUMNS[$key])) {
                $columns[$key] = self::ALLOWED_COLUMNS[$key];
            }
        }

        $rows = $this->exportData->rows($validated);
        $workbook = $this->buildSpreadsheet($rows, $columns);
        $filename = 'Laporan_Pegawai_Custom_' . now()->format('Ymd') . '.xlsx';

        return response()->streamDownload(function () use ($workbook): void {
            try {
                (new Xlsx($workbook))->save('php://output');
            } finally {
                $workbook->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma'        => 'no-cache',
            'Expires'       => '0',
        ]);
    }

    /**
     * @param Collection<int, array<string, string>> $rows
     * @param array<string, string> $columns
     */
    private function buildSpreadsheet(Collection $rows, array $columns): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Pegawai Custom');
        $sheet->setShowGridlines(false);

        $lastColumn  = Coordinate::stringFromColumnIndex(count($columns));
        $columnIndex = 1;

        foreach ($columns as $key => $label) {
            $col = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($col)->setWidth(self::COLUMN_WIDTHS[$key] ?? 20);
            $sheet->setCellValue($col . '1', $label);
            $columnIndex++;
        }

        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A1:' . $lastColumn . '1')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Calibri'],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
        ]);

        foreach ($rows->values() as $index => $row) {
            $rowNumber   = $index + 2;
            $columnIndex = 1;

            foreach (array_keys($columns) as $key) {
                $col   = Coordinate::stringFromColumnIndex($columnIndex);
                $value = (string) ($row[$key] ?? '-');

                // Simpan semua nilai sebagai string literal untuk mencegah injeksi formula.
                $sheet->setCellValueExplicit($col . $rowNumber, $value, DataType::TYPE_STRING);
                $columnIndex++;
            }

            $sheet->getRowDimension($rowNumber)->setRowHeight(18);
            $sheet->getStyle('A' . $rowNumber . ':' . $lastColumn . $rowNumber)->applyFromArray([
                'font'      => ['size' => 10, 'name' => 'Calibri'],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $index % 2 === 0 ? 'FFFFFF' : 'EEF2FF']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);
        }

        $lastRow = max(1, $rows->count() + 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $lastColumn . $lastRow);

        return $spreadsheet;
    }
}
