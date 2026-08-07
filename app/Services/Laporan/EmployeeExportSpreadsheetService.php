<?php

namespace App\Services\Laporan;

use App\Support\Laporan\ExcelStyleHelper;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

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

        $sheet->getRowDimension(1)->setRowHeight(32);
        ExcelStyleHelper::applyHeaderStyle($sheet, 'A1:'.$lastColumn.'1');

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

        }

        $lastRow = max(1, $rows->count() + 1);
        if ($rows->isNotEmpty()) {
            ExcelStyleHelper::applyRowStyle($sheet, 'A2:'.$lastColumn.$lastRow);
        }

        ExcelStyleHelper::applyGlobalSetup($sheet, 'A1:'.$lastColumn.$lastRow);

        return $spreadsheet;
    }
}
