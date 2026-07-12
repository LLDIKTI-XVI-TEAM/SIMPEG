<?php

namespace App\Actions\Reports;

use App\Models\Employee;
use App\Models\RankHistory;
use App\Models\RefGolongan;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportRankHistoryReportAction
{
    public function preview(array $filters): Collection
    {
        return $this->rows($filters)->take(10)->values();
    }

    public function filterOptions(): array
    {
        return [
            'employees' => Employee::query()->orderBy('nama_lengkap')->get(['id', 'nama_lengkap', 'nip']),
            'ranks' => RefGolongan::query()->orderBy('urutan')->get(['id', 'kode', 'nama']),
        ];
    }

    public function executeExcel(array $filters): StreamedResponse
    {
        $rows = $this->rows($filters);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Riwayat Kepangkatan');
        $headers = [
            'No',
            'NIP',
            'Nama Pegawai',
            'Golongan',
            'TMT Pangkat',
            'Nomor SK',
            'Tanggal SK',
        ];

        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column.'1', $header);
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ($rows as $rowIndex => $row) {
            $sheet->fromArray([
                $rowIndex + 1,
                $row['nip'],
                $row['nama'],
                $row['golongan'],
                $row['tmt'],
                $row['no_sk'],
                $row['tanggal_sk'],
            ], null, 'A'.($rowIndex + 2));
        }

        $filename = 'Riwayat_Kepangkatan_LLDIKTI_XVI_'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function rows(array $filters): Collection
    {
        $query = RankHistory::query()
            ->with(['employee', 'golongan'])
            ->orderByDesc('tmt_pangkat');

        if (filled($filters['employee_id'] ?? null)) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (filled($filters['golongan_id'] ?? null)) {
            $query->where('golongan_id', $filters['golongan_id']);
        }
        if (filled($filters['tahun'] ?? null)) {
            $query->whereYear('tmt_pangkat', $filters['tahun']);
        }

        return $query->get()->map(fn (RankHistory $history): array => [
            'nip' => $history->employee?->nip ?? '-',
            'nama' => $history->employee?->nama_lengkap ?? '-',
            'golongan' => $history->golongan?->kode ?? '-',
            'tmt' => $history->tmt_pangkat?->toDateString() ?? '-',
            'no_sk' => $history->no_sk ?? '-',
            'tanggal_sk' => $history->tanggal_sk?->toDateString() ?? '-',
        ]);
    }
}
