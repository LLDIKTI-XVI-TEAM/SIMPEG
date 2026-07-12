<?php

namespace App\Actions\Cuti;

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Queries\Cuti\CutiRekapQuery;
use App\Support\Cuti\CutiReportStatusFormatter;
use Illuminate\Http\RedirectResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

class ExportCutiExcelAction
{
    private const MAX_ROWS = 5000;

    public function __construct(
        private readonly CutiRekapQuery $rekapQuery,
        private readonly CutiReportStatusFormatter $statusFormatter,
    ) {}

    /**
     * Mengekspor detail dan saldo materialized dengan batas eksplisit serta pertahanan formula spreadsheet.
     *
     * @param  array<string, mixed>  $filters
     */
    public function execute(array $filters): Response|RedirectResponse
    {
        $detailQuery = $this->rekapQuery->detailRows($filters);
        $count = (clone $detailQuery)->count();

        if ($count > self::MAX_ROWS) {
            return back()->with(
                'error',
                "Laporan memuat {$count} baris, melebihi batas ".self::MAX_ROWS.'. Persempit filter lalu coba lagi.',
            );
        }

        $balanceQuery = $this->rekapQuery->balanceRows($filters);
        $balanceCount = (clone $balanceQuery)->count();

        if ($balanceCount > self::MAX_ROWS) {
            return back()->with(
                'error',
                "Laporan saldo memuat {$balanceCount} baris, melebihi batas ".self::MAX_ROWS.'. Persempit filter lalu coba lagi.',
            );
        }

        $details = $detailQuery->get();
        $balances = $balanceQuery->get();
        $spreadsheet = new Spreadsheet;
        $detailSheet = $spreadsheet->getActiveSheet();
        $detailSheet->setTitle('Detail Cuti');
        $this->writeDetailSheet($detailSheet, $details);

        $balanceSheet = $spreadsheet->createSheet();
        $balanceSheet->setTitle('Saldo Cuti');
        $this->writeBalanceSheet($balanceSheet, $balances);

        $filename = 'Laporan_Cuti_'.now()->format('Ymd_His').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            try {
                (new Xlsx($spreadsheet))->save('php://output');
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /** @param iterable<int, LeaveRequest> $rows */
    private function writeDetailSheet(Worksheet $sheet, iterable $rows): void
    {
        $headers = ['No', 'NIP', 'Nama', 'Nama (Aman)', 'Jenis Cuti', 'Tanggal Mulai', 'Tanggal Selesai', 'Hari Kerja', 'Status'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        $sheet->freezePane('A2');

        foreach ($rows as $index => $leaveRequest) {
            $row = $index + 2;
            $name = $leaveRequest->employee?->nama_lengkap ?? '-';
            $sheet->setCellValue('A'.$row, $index + 1);
            $this->setSafeText($sheet, 'B'.$row, $leaveRequest->employee?->nip ?? '-');
            $this->setSafeText($sheet, 'C'.$row, $name);
            $this->setSafeText($sheet, 'D'.$row, $name);
            $this->setSafeText($sheet, 'E'.$row, $leaveRequest->jenisCuti?->nama ?? '-');
            $sheet->setCellValue('F'.$row, Date::dateTimeToExcel($leaveRequest->tanggal_mulai));
            $sheet->setCellValue('G'.$row, Date::dateTimeToExcel($leaveRequest->tanggal_selesai));
            $sheet->getStyle('F'.$row.':G'.$row)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $sheet->setCellValue('H'.$row, $leaveRequest->jumlah_hari_kerja);
            $this->setSafeText($sheet, 'I'.$row, $this->statusFormatter->format($leaveRequest));
        }
    }

    /** @param iterable<int, LeaveBalance> $rows */
    private function writeBalanceSheet(Worksheet $sheet, iterable $rows): void
    {
        $headers = ['No', 'NIP', 'Nama', 'Tahun', 'Sisa N-2', 'Sisa N-1', 'Sisa Tahun Berjalan', 'Sisa', 'Hangus'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        $sheet->freezePane('A2');

        foreach ($rows as $index => $balance) {
            $row = $index + 2;
            $sheet->setCellValue('A'.$row, $index + 1);
            $this->setSafeText($sheet, 'B'.$row, $balance->employee?->nip ?? '-');
            $this->setSafeText($sheet, 'C'.$row, $balance->employee?->nama_lengkap ?? '-');
            $sheet->setCellValue('D'.$row, $balance->tahun);
            $sheet->setCellValue('E'.$row, $balance->sisa_n2);
            $sheet->setCellValue('F'.$row, $balance->sisa_n1);
            $sheet->setCellValue('G'.$row, $balance->sisa_tahun_berjalan);
            $sheet->setCellValue('H'.$row, $balance->sisa);
            $sheet->setCellValue('I'.$row, $balance->hangus);
        }
    }

    /**
     * Apostrof memaksa aplikasi spreadsheet memperlakukan input pengguna sebagai teks, bukan formula.
     */
    private function safeText(string $value): string
    {
        return preg_match('/^(?:[\t\r\n]|\s*[=+\-@])/u', $value) === 1 ? "'".$value : $value;
    }

    private function setSafeText(Worksheet $sheet, string $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $this->safeText($value), DataType::TYPE_STRING);
    }
}
