<?php

namespace App\Actions\Reports;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportLeaveReportAction
{
    public function preview(array $filters): Collection
    {
        return $this->rows($filters)->take(10)->values();
    }

    public function filterOptions(): array
    {
        return [
            'employees' => Employee::query()->orderBy('nama_lengkap')->get(['id', 'nama_lengkap', 'nip']),
            'units' => RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']),
            'leaveTypes' => RefJenisCuti::query()->orderBy('nama')->get(['id', 'nama']),
        ];
    }

    public function execute(array $filters): StreamedResponse
    {
        $rows = $this->rows($filters);
        $spreadsheet = new Spreadsheet;
        $detailSheet = $spreadsheet->getActiveSheet();
        $detailSheet->setTitle('Detail Cuti');
        $this->writeSheet($detailSheet, [
            'No',
            'NIP',
            'Nama Pegawai',
            'Jenis Cuti',
            'Tanggal Mulai',
            'Tanggal Selesai',
            'Jumlah Hari',
            'Status',
        ], $rows->values()->map(fn (array $row, int $index): array => [
            $index + 1,
            $row['nip'],
            $row['nama'],
            $row['jenis'],
            $row['mulai'],
            $row['selesai'],
            $row['hari'],
            $row['status'],
        ]));

        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('Ringkasan Cuti');
        $this->writeSheet($summarySheet, [
            'No',
            'NIP',
            'Nama Pegawai',
            'Jenis Cuti',
            'Total Hari Disetujui',
            'Sisa Saldo Cuti Tahunan',
        ], $this->summaryRows($rows, $filters));

        $spreadsheet->setActiveSheetIndex(0);
        $period = $this->periodLabel($filters);
        $filename = 'Rekap_Cuti_'.$period.'_'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function rows(array $filters): Collection
    {
        $query = LeaveRequest::query()
            ->with(['employee', 'jenisCuti'])
            ->orderByDesc('tanggal_mulai');

        if (filled($filters['tahun'] ?? null)) {
            $query->whereYear('tanggal_mulai', $filters['tahun']);
        }
        if (filled($filters['bulan'] ?? null)) {
            $query->whereMonth('tanggal_mulai', $filters['bulan']);
        }
        if (filled($filters['employee_id'] ?? null)) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (filled($filters['jenis_cuti_id'] ?? null)) {
            $query->where('jenis_cuti_id', $filters['jenis_cuti_id']);
        }
        if (filled($filters['unit_kerja_id'] ?? null)) {
            $query->whereHas('employee.positionHistories', fn ($positionQuery) => $positionQuery
                ->where('is_latest', true)
                ->where('unit_kerja_id', $filters['unit_kerja_id']));
        }

        return $query->get()->map(fn (LeaveRequest $leave): array => [
            'employee_id' => $leave->employee_id,
            'nip' => $leave->employee?->nip ?? '-',
            'nama' => $leave->employee?->nama_lengkap ?? '-',
            'jenis' => $leave->jenisCuti?->nama ?? '-',
            'mulai' => $leave->tanggal_mulai?->toDateString() ?? '-',
            'selesai' => $leave->tanggal_selesai?->toDateString() ?? '-',
            'hari' => $leave->jumlah_hari_kerja,
            'status' => $this->statusLabel($leave->status),
            'is_approved' => $leave->status === 'disetujui',
        ]);
    }

    private function summaryRows(Collection $rows, array $filters): Collection
    {
        $year = (int) ($filters['tahun'] ?? now()->year);
        $balances = LeaveBalance::query()
            ->where('tahun', $year)
            ->whereIn('employee_id', $rows->pluck('employee_id')->unique())
            ->get()
            ->keyBy('employee_id');

        return $rows
            ->where('is_approved', true)
            ->groupBy(fn (array $row): string => $row['employee_id'].'|'.$row['jenis'])
            ->values()
            ->map(function (Collection $group, int $index) use ($balances): array {
                $first = $group->first();
                $balance = $balances->get($first['employee_id']);

                return [
                    $index + 1,
                    $first['nip'],
                    $first['nama'],
                    $first['jenis'],
                    $group->sum('hari'),
                    $balance?->sisa ?? '-',
                ];
            });
    }

    private function writeSheet($sheet, array $headers, Collection $rows): void
    {
        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column.'1', $header);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2), $value);
            }
        }
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'disetujui' => 'Disetujui',
            'perlu_perubahan' => 'Perubahan',
            'ditangguhkan' => 'Ditangguhkan',
            'tidak_disetujui' => 'Tidak Disetujui',
            default => 'Menunggu Persetujuan',
        };
    }

    private function periodLabel(array $filters): string
    {
        $year = $filters['tahun'] ?? 'Semua_Tahun';
        $month = $filters['bulan'] ?? null;

        return $month ? sprintf('%04d-%02d', $year, $month) : (string) $year;
    }
}
