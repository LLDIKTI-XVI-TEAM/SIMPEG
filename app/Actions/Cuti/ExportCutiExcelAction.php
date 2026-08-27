<?php

namespace App\Actions\Cuti;

use App\Data\Cuti\CutiRekapReadRow;
use App\Models\LeaveBalance;
use App\Queries\Cuti\CutiRekapQuery;
use App\Support\Laporan\ExcelStyleHelper;
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
    ) {}

    /**
     * Mengekspor detail dan saldo materialized dengan batas eksplisit serta pertahanan formula spreadsheet.
     *
     * @param  array<string, mixed>  $filters
     */
    public function execute(array $filters): Response|RedirectResponse
    {
        $count = $this->rekapQuery->detailCount($filters);

        if ($count > self::MAX_ROWS) {
            return back()->with(
                'error',
                "Laporan memuat {$count} baris, melebihi batas ".self::MAX_ROWS.'. Persempit filter lalu coba lagi.',
            );
        }

        $details = $this->rekapQuery->allDetailRows($filters);

        // Sheet saldo adalah state materialized per tahun dan menentukan scope-nya
        // sendiri lewat filter unit/pegawai/tahun. Ia tidak boleh dibatasi oleh
        // keberadaan transaksi pada sheet detail, karena pegawai yang belum pernah
        // mengajukan cuti tetap memiliki hak yang wajib terlapor.
        $balanceQuery = $this->rekapQuery->balanceRows($filters);

        $balanceCount = (clone $balanceQuery)->count();

        if ($balanceCount > self::MAX_ROWS) {
            return back()->with(
                'error',
                "Laporan saldo memuat {$balanceCount} baris, melebihi batas ".self::MAX_ROWS.'. Persempit filter lalu coba lagi.',
            );
        }

        $balances = $balanceQuery->get();
        $spreadsheet = new Spreadsheet;
        $detailSheet = $spreadsheet->getActiveSheet();
        $detailSheet->setTitle('Detail Cuti');
        $this->writeDetailSheet($detailSheet, $details);

        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('Ringkasan Cuti');
        $this->writeSummarySheet(
            $summarySheet,
            $this->rekapQuery->summaryRows($filters),
            $this->rekapQuery->saldoYear($filters),
        );

        $balanceSheet = $spreadsheet->createSheet();
        $balanceSheet->setTitle('Saldo Cuti');
        $this->writeBalanceSheet($balanceSheet, $balances);

        $filename = 'Rekap_Cuti_'.$this->rekapQuery->periodLabel($filters).'_'.now()->format('Ymd').'.xlsx';

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

    /** @param iterable<int, CutiRekapReadRow> $rows */
    private function writeDetailSheet(Worksheet $sheet, iterable $rows): void
    {
        $headers = ['No', 'NIP', 'Nama', 'Jenis Cuti', 'Tanggal Mulai', 'Tanggal Selesai', 'Hari Kerja', 'Sumber', 'Status'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        $sheet->freezePane('A2');

        foreach ($rows as $index => $rowData) {
            $row = $index + 2;
            $sheet->setCellValue('A'.$row, $index + 1);
            $this->setSafeText($sheet, 'B'.$row, $rowData->nip);
            $this->setSafeText($sheet, 'C'.$row, $rowData->nama);
            $this->setSafeText($sheet, 'D'.$row, $rowData->jenis);
            $sheet->setCellValue('E'.$row, Date::dateTimeToExcel($rowData->tanggalMulai));
            $sheet->setCellValue('F'.$row, Date::dateTimeToExcel($rowData->tanggalSelesai));
            $sheet->getStyle('E'.$row.':F'.$row)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
            $sheet->setCellValue('G'.$row, $rowData->hari);
            $this->setSafeText($sheet, 'H'.$row, $rowData->sourceLabel);
            $this->setSafeText($sheet, 'I'.$row, $rowData->statusLabel);
        }

        $this->applyTableStyles($sheet, 'I', empty($rows) ? 1 : (is_array($rows) || $rows instanceof \Countable ? count($rows) + 1 : 1000));
    }

    /**
     * Ringkasan hari cuti disetujui per pegawai per jenis; saldo '-' berarti belum ada baris saldo tahun tersebut.
     *
     * Tahun saldo dicantumkan pada judul kolom karena rekap tanpa filter periode dapat mencakup
     * beberapa tahun sedangkan saldo yang ditampilkan hanya milik satu tahun.
     *
     * @param  iterable<int, array{employee_id: string, nip: string, nama: string, jenis: string, total_hari: int, sisa_saldo: int|string, saldo_tahun: int}>  $rows
     */
    private function writeSummarySheet(Worksheet $sheet, iterable $rows, int $saldoTahun): void
    {
        $headers = ['No', 'NIP', 'Nama Pegawai', 'Jenis Cuti', 'Total Hari Disetujui', "Sisa Saldo Cuti Tahunan {$saldoTahun}"];
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        $sheet->freezePane('A2');

        foreach ($rows as $index => $summary) {
            $row = $index + 2;
            $sheet->setCellValue('A'.$row, $index + 1);
            $this->setSafeText($sheet, 'B'.$row, $summary['nip']);
            $this->setSafeText($sheet, 'C'.$row, $summary['nama']);
            $this->setSafeText($sheet, 'D'.$row, $summary['jenis']);
            $sheet->setCellValueExplicit('E'.$row, $summary['total_hari'], DataType::TYPE_NUMERIC);
            // Saldo bertipe campuran: angka bila baris saldo ada, penanda '-' bila belum ada.
            // Penanda tidak melalui safeText karena nilainya dibangkitkan sistem, bukan input pengguna.
            $sheet->setCellValueExplicit(
                'F'.$row,
                $summary['sisa_saldo'],
                is_int($summary['sisa_saldo']) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING,
            );
        }

        $this->applyTableStyles($sheet, 'F', empty($rows) ? 1 : (is_array($rows) || $rows instanceof \Countable ? count($rows) + 1 : 1000));
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

        $this->applyTableStyles($sheet, 'I', empty($rows) ? 1 : (is_array($rows) || $rows instanceof \Countable ? count($rows) + 1 : 1000));
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

    private function applyTableStyles(Worksheet $sheet, string $lastColumn, int $lastRow): void
    {
        $sheet->getRowDimension(1)->setRowHeight(32);
        ExcelStyleHelper::applyHeaderStyle($sheet, 'A1:'.$lastColumn.'1');

        if ($lastRow >= 2) {
            ExcelStyleHelper::applyRowStyle($sheet, 'A2:'.$lastColumn.$lastRow);
        }

        ExcelStyleHelper::applyGlobalSetup($sheet, 'A1:'.$lastColumn.$lastRow);

        foreach (range('A', $lastColumn) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
