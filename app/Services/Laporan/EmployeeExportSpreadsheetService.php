<?php

namespace App\Services\Laporan;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class EmployeeExportSpreadsheetService
{
    /** @var array<string, int> */
    private const COLUMN_WIDTHS = [
        'no' => 5,
        'nip' => 24,
        'nama' => 32,
        'golongan' => 12,
        'jabatan' => 38,
        'unit' => 24,
        'jenis' => 18,
        'status' => 16,
        'pendidikan' => 22,
        'tanggal_pensiun' => 18,
    ];

    /**
     * @param  Collection<int, array<string, string>>  $rows
     * @param  array<string, string>  $columns
     */
    public function make(Collection $rows, array $columns, string $sheetTitle): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($sheetTitle);
        $sheet->setShowGridlines(false);

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $columnIndex = 1;

        foreach ($columns as $key => $label) {
            $column = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->getColumnDimension($column)->setWidth(self::COLUMN_WIDTHS[$key] ?? 20);
            $sheet->setCellValue($column.'1', $label);
            $columnIndex++;
        }

        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getStyle('A1:'.$lastColumn.'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11, 'name' => 'Calibri'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
        ]);

        foreach ($rows->values() as $index => $row) {
            $rowNumber = $index + 2;
            $columnIndex = 1;

            foreach (array_keys($columns) as $key) {
                $column = Coordinate::stringFromColumnIndex($columnIndex);
                $value = $row[$key] ?? '-';

                if ($key === 'nip') {
                    $sheet->setCellValueExplicit($column.$rowNumber, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($column.$rowNumber, $value);
                }

                $columnIndex++;
            }

            $sheet->getRowDimension($rowNumber)->setRowHeight(18);
            $sheet->getStyle('A'.$rowNumber.':'.$lastColumn.$rowNumber)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $index % 2 === 0 ? 'FFFFFF' : 'EEF2FF']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);
        }

        $lastRow = max(1, $rows->count() + 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.$lastRow);

        return $spreadsheet;
    }
}
