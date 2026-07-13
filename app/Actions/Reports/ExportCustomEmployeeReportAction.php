<?php

namespace App\Actions\Reports;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportCustomEmployeeReportAction
{
    private const COLUMN_LABELS = [
        'nip' => 'NIP',
        'nama' => 'Nama Pegawai',
        'status' => 'Status Pegawai',
        'jabatan' => 'Jabatan',
        'unit' => 'Unit/Tim Kerja',
        'golongan' => 'Golongan',
        'jenis_pegawai' => 'Jenis Pegawai',
        'pendidikan' => 'Pendidikan Terakhir',
        'tanggal_pensiun' => 'Tanggal Pensiun',
    ];

    public function preview(array $filters): Collection
    {
        return $this->rows($filters)->take(10)->values();
    }

    public function filterOptions(): array
    {
        return [
            'statuses' => RefStatusPegawai::query()->orderBy('nama')->get(['id', 'nama']),
            'units' => RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']),
            'employeeTypes' => RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']),
            'ranks' => Employee::query()
                ->whereNotNull('golongan_terakhir')
                ->distinct()
                ->orderBy('golongan_terakhir')
                ->pluck('golongan_terakhir'),
            'positions' => Employee::query()
                ->whereNotNull('jabatan_terakhir')
                ->distinct()
                ->orderBy('jabatan_terakhir')
                ->pluck('jabatan_terakhir'),
        ];
    }

    public function execute(array $payload): StreamedResponse
    {
        $columns = collect($payload['columns'])->filter(
            fn (mixed $column): bool => array_key_exists($column, self::COLUMN_LABELS),
        )->values();
        $rows = $this->rows($payload);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Pegawai Custom');

        foreach ($columns as $index => $column) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).'1';
            $sheet->setCellValue($cell, self::COLUMN_LABELS[$column]);
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setAutoSize(true);
        }

        $lastColumn = Coordinate::stringFromColumnIndex($columns->count());
        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$lastColumn}1")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach ($rows as $rowIndex => $row) {
            foreach ($columns as $columnIndex => $column) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2),
                    (string) $row[$column],
                    DataType::TYPE_STRING,
                );
            }
        }

        $filename = 'Daftar_Nominatif_Custom_LLDIKTI_XVI_'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function rows(array $filters): Collection
    {
        $query = Employee::query()
            ->with([
                'jenisPegawai:id,nama',
                'statusPegawai:id,nama',
                'positionHistories' => fn ($positionQuery) => $positionQuery
                    ->where('is_latest', true)
                    ->with('unitKerja:id,nama'),
            ])
            ->orderBy('nama_lengkap');

        foreach (['status_pegawai_id', 'jenis_pegawai_id'] as $filter) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($filter, $filters[$filter]);
            }
        }

        if (filled($filters['unit_kerja_id'] ?? null)) {
            $query->whereHas('positionHistories', fn ($positionQuery) => $positionQuery
                ->where('is_latest', true)
                ->where('unit_kerja_id', $filters['unit_kerja_id']));
        }
        if (filled($filters['golongan'] ?? null)) {
            $query->where('golongan_terakhir', 'like', $filters['golongan'].'%');
        }
        if (filled($filters['jabatan'] ?? null)) {
            $query->where('jabatan_terakhir', $filters['jabatan']);
        }
        if (filled($filters['pensiun_dari'] ?? null)) {
            $query->whereDate('tanggal_pensiun', '>=', $filters['pensiun_dari']);
        }
        if (filled($filters['pensiun_sampai'] ?? null)) {
            $query->whereDate('tanggal_pensiun', '<=', $filters['pensiun_sampai']);
        }

        return $query->get()->map(function (Employee $employee): array {
            $latestPosition = $employee->positionHistories->first();

            return [
                'nip' => $employee->nip,
                'nama' => $employee->nama_lengkap,
                'status' => $employee->statusPegawai?->nama ?? $employee->status_aktif ?? '-',
                'jabatan' => $employee->jabatan_terakhir ?? '-',
                'unit' => $latestPosition?->unitKerja?->nama ?? '-',
                'golongan' => $employee->golongan_terakhir ?? '-',
                'jenis_pegawai' => $employee->jenisPegawai?->nama ?? '-',
                'pendidikan' => $employee->pendidikan_terakhir ?? '-',
                'tanggal_pensiun' => $employee->tanggal_pensiun?->format('Y-m-d') ?? '-',
            ];
        });
    }
}
