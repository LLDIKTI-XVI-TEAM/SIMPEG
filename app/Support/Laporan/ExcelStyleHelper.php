<?php

namespace App\Support\Laporan;

use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ExcelStyleHelper
{
    /**
     * Terapkan gaya visual (styling) standar SIMPEG untuk header tabel Excel.
     */
    public static function applyHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Calibri'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5A83']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
        ]);
    }

    /**
     * Terapkan gaya visual standar SIMPEG untuk baris data Excel (warna biru muda cerah, border konsisten).
     */
    public static function applyRowStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '111827']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9F2FB']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
        ]);
    }

    /**
     * Pengaturan global untuk print setup, freeze pane, dan auto filter.
     */
    public static function applyGlobalSetup(Worksheet $sheet, string $autoFilterRange): void
    {
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($autoFilterRange);
        
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, 1);
            
        $sheet->getPageMargins()
            ->setTop(0.3)
            ->setRight(0.25)
            ->setBottom(0.3)
            ->setLeft(0.25);
    }
}
