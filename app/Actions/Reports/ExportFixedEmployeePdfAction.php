<?php

namespace App\Actions\Reports;

use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportFixedEmployeePdfAction
{
    public function execute(Collection $rows): StreamedResponse
    {
        $content = $this->document($rows);
        $filename = 'Laporan_Nominatif_Pegawai_'.now()->format('Ymd').'.pdf';

        return response()->streamDownload(function () use ($content): void {
            echo $content;
        }, $filename, ['Content-Type' => 'application/pdf']);
    }

    private function document(Collection $rows): string
    {
        $pages = $rows->chunk(33);
        if ($pages->isEmpty()) {
            $pages = collect([collect()]);
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pageObjects = [];

        foreach ($pages as $pageIndex => $pageRows) {
            $pageObject = count($objects) + 1;
            $contentObject = $pageObject + 1;
            $pageObjects[] = $pageObject;
            $stream = $this->pageStream($pageRows, $pageIndex + 1, $pages->count());
            $objects[$pageObject] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentObject} 0 R >>";
            $objects[$contentObject] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.collect($pageObjects)->map(fn (int $id): string => "{$id} 0 R")->implode(' ').'] /Count '.$pages->count().' >>';

        return $this->serialize($objects);
    }

    private function pageStream(Collection $rows, int $page, int $totalPages): string
    {
        $columns = [
            ['No', 30, 4],
            ['NIP', 110, 19],
            ['Nama Pegawai', 190, 36],
            ['Golongan', 65, 10],
            ['Jabatan', 170, 32],
            ['Unit Kerja', 125, 22],
            ['Jenis Pegawai', 92, 16],
        ];
        $left = 30;
        $tableTop = 520;
        $headerHeight = 16;
        $rowHeight = 14;
        $tableBottom = $tableTop - $headerHeight - ($rowHeight * count($rows));

        $stream = [
            // 1. Header Banner
            '0.07 0.18 0.57 rg',
            '30 550 782 24 re f',
            '1 1 1 rg',
            $this->text(44, 558, 12, 'LAPORAN NOMINATIF PEGAWAI'),

            // 2. Sub-header & Meta Info
            '0.2 0.2 0.2 rg',
            $this->text(30, 532, 8, 'LLDIKTI Wilayah XVI | Tanggal Cetak: '.now()->translatedFormat('d F Y')),
            $this->text(725, 532, 8, "Halaman {$page} dari {$totalPages}"),

            // 3. Table Header Background
            '0.94 0.95 0.97 rg',
            "{$left} ".($tableTop - $headerHeight)." 782 {$headerHeight} re f",

            // 4. Lines setup
            '0 0 0 RG',
            '0 0 0 rg',
            '0.5 w',
            // Horizontal line on top of header
            "{$left} {$tableTop} m ".($left + 782)." {$tableTop} l S",
            // Horizontal line below header
            "{$left} ".($tableTop - $headerHeight).' m '.($left + 782).' '.($tableTop - $headerHeight).' l S',
        ];

        // Header column texts
        $x = $left;
        foreach ($columns as [$label, $width]) {
            $stream[] = $this->text($x + 4, $tableTop - $headerHeight + 5, 8, $label);
            $x += $width;
        }

        // Data rows
        $currentRowTop = $tableTop - $headerHeight;
        foreach ($rows->values() as $index => $row) {
            $rowBottom = $currentRowTop - $rowHeight;
            $stream[] = "{$left} {$rowBottom} m ".($left + 782)." {$rowBottom} l S";

            $x = $left;
            $values = [
                (string) ($index + 1 + (($page - 1) * 33)),
                (string) ($row['nip'] ?? '-'),
                (string) ($row['nama'] ?? '-'),
                (string) ($row['golongan'] ?? '-'),
                (string) ($row['jabatan'] ?? '-'),
                (string) ($row['unit'] ?? '-'),
                (string) ($row['jenis'] ?? '-'),
            ];

            foreach ($columns as $columnIndex => [, $width, $limit]) {
                $stream[] = $this->text($x + 4, $rowBottom + 4, 7, $this->truncate($values[$columnIndex], $limit));
                $x += $width;
            }

            $currentRowTop = $rowBottom;
        }

        // Vertical column separator lines
        $x = $left;
        foreach ($columns as [, $width]) {
            $stream[] = "{$x} {$tableBottom} m {$x} {$tableTop} l S";
            $x += $width;
        }
        // Rightmost vertical line
        $stream[] = "{$x} {$tableBottom} m {$x} {$tableTop} l S";

        return implode("\n", $stream);
    }

    private function text(int $x, int $y, int $size, string $text): string
    {
        return "BT /F1 {$size} Tf {$x} {$y} Td (".$this->escape($text).') Tj ET';
    }

    private function truncate(string $text, int $limit): string
    {
        return mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
    }

    private function escape(string $text): string
    {
        $encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);

        return preg_replace('/[\x00-\x1F\x7F]/', ' ', $escaped) ?? '';
    }

    private function serialize(array $objects): string
    {
        ksort($objects);
        $output = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($output);
            $output .= "{$id} 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($output);
        $output .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach (array_keys($objects) as $id) {
            $output .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        return $output."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
