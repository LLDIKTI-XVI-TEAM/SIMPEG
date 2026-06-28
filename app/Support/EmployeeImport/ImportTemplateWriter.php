<?php

namespace App\Support\EmployeeImport;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportTemplateWriter
{
    /**
     * Tulis template sebagai unduhan streaming (xlsx berstyle atau csv ber-BOM).
     *
     * @param  array<int, string>  $headers
     * @param  array<string, string|null>  $example  baris contoh (map header -> nilai)
     */
    public function stream(string $type, array $headers, array $example, string $format): StreamedResponse
    {
        $filename = 'template_'.$type.'.'.$format;

        return $format === 'csv'
            ? $this->streamCsv($filename, $headers, $example)
            : $this->streamXlsx($type, $filename, $headers, $example);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<string, string|null>  $example
     */
    private function streamCsv(string $filename, array $headers, array $example): StreamedResponse
    {
        $exampleRow = $this->orderedExample($headers, $example);

        return response()->streamDownload(function () use ($headers, $exampleRow) {
            $output = fopen('php://output', 'w');
            // BOM agar Excel membaca UTF-8 dengan benar.
            echo "\xEF\xBB\xBF";
            fputcsv($output, $headers);
            fputcsv($output, $exampleRow);
            fclose($output);
        }, $filename, $this->downloadHeaders('text/csv; charset=UTF-8', $filename));
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<string, string|null>  $example
     */
    private function streamXlsx(string $type, string $filename, array $headers, array $example): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Template '.ucfirst($type));

        foreach ($headers as $index => $header) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($col.'1', $header);
            $sheet->getStyle($col.'1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '122E92']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CACFE0']]],
            ]);
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getRowDimension(1)->setRowHeight(30);

        // Baris 2 = baris contoh (memuat penanda agar di-skip importer bila tertinggal).
        // Ditulis eksplisit sebagai string agar NIP/angka panjang tidak berubah jadi notasi ilmiah.
        $exampleRow = $this->orderedExample($headers, $example);
        foreach ($exampleRow as $index => $value) {
            $col = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValueExplicit($col.'2', (string) $value, DataType::TYPE_STRING);
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        for ($r = 2; $r <= 16; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(20);
            $sheet->getStyle('A'.$r.':'.$lastCol.$r)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
            ]);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, $this->downloadHeaders(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $filename
        ));
    }

    /**
     * Urutkan nilai contoh mengikuti urutan header.
     *
     * @param  array<int, string>  $headers
     * @param  array<string, string|null>  $example
     * @return array<int, string|null>
     */
    private function orderedExample(array $headers, array $example): array
    {
        return array_map(fn (string $h) => $example[$h] ?? null, $headers);
    }

    /**
     * @return array<string, string>
     */
    private function downloadHeaders(string $contentType, string $filename): array
    {
        return [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ];
    }
}
