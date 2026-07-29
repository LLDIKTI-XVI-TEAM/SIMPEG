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
        $pages = $rows->chunk(34);
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
            ['No', 30, 3],
            ['NIP', 110, 18],
            ['Nama Pegawai', 180, 32],
            ['Golongan', 70, 10],
            ['Jabatan', 180, 32],
            ['Unit Kerja', 120, 20],
            ['Jenis', 92, 14],
        ];
        $left = 30;
        $top = 532;
        $rowHeight = 14;
        $stream = [
            '0.07 0.18 0.57 rg',
            '30 552 782 20 re f',
            '1 1 1 rg',
            $this->text(42, 559, 13, 'LAPORAN NOMINATIF PEGAWAI'),
            '0 0 0 rg',
            $this->text(42, 542, 8, 'LLDIKTI Wilayah XVI | Dicetak '.now()->translatedFormat('d M Y')),
            $this->text(700, 542, 8, "Halaman {$page}/{$totalPages}"),
            '0.95 g',
            "{$left} {$top} 782 {$rowHeight} re f",
            '0 0 0 RG',
            '0 0 0 rg',
            '0.6 w',
        ];
        $x = $left;

        foreach ($columns as [$label, $width]) {
            $stream[] = "{$x} ".($top - ($rowHeight * (count($rows) + 1)))." m {$x} ".($top + $rowHeight).' l S';
            $stream[] = $this->text($x + 3, $top + 4, 7, $label);
            $x += $width;
        }

        $stream[] = "{$x} ".($top - ($rowHeight * (count($rows) + 1)))." m {$x} ".($top + $rowHeight).' l S';
        $stream[] = "{$left} {$top} m ".($left + 782)." {$top} l S";
        $stream[] = "{$left} ".($top + $rowHeight).' m '.($left + 782).' '.($top + $rowHeight).' l S';

        foreach ($rows->values() as $index => $row) {
            $y = $top - (($index + 1) * $rowHeight);
            $stream[] = "{$left} {$y} m ".($left + 782)." {$y} l S";
            $x = $left;
            $values = [
                (string) ($index + 1 + (($page - 1) * 34)),
                (string) ($row['nip'] ?? '-'),
                (string) ($row['nama'] ?? '-'),
                (string) ($row['golongan'] ?? '-'),
                (string) ($row['jabatan'] ?? '-'),
                (string) ($row['unit'] ?? '-'),
                (string) ($row['jenis'] ?? '-'),
            ];

            foreach ($columns as $columnIndex => [, $width, $limit]) {
                $stream[] = $this->text($x + 3, $y + 4, 7, $this->truncate($values[$columnIndex], $limit));
                $x += $width;
            }
        }

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
