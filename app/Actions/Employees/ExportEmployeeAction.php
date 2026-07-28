<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportEmployeeAction
{
    /**
     * Mengekspor data pegawai menjadi file Excel dan mengembalikannya sebagai StreamedResponse.
     */
    public function execute(Request $request): StreamedResponse
    {
        $requestedIds = collect($request->input('ids', []))
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values();

        $query = Employee::query()->with(['jenisPegawai:id,nama', 'statusPegawai:id,nama']);

        if ($requestedIds->isNotEmpty()) {
            $query->whereIn('id', $requestedIds->all());
        } else {
            $search = mb_strtolower(trim((string) $request->query('search', '')));
            $golongan = trim((string) $request->query('golongan', ''));
            $unitKerjaId = trim((string) $request->query('unit_kerja_id', ''));
            $jenisPegawaiId = trim((string) $request->query('jenis_pegawai_id', ''));
            $statusPegawaiId = trim((string) $request->query('status_pegawai_id', ''));
            $statusAktif = trim((string) $request->query('status_aktif', ''));

            if ($unitKerjaId === '' && $request->filled('unit')) {
                $unitKerjaId = RefUnitKerja::where('nama', $request->query('unit'))->value('id') ?? '';
            }
            if ($jenisPegawaiId === '' && $request->filled('jenis')) {
                $jenisPegawaiId = RefJenisPegawai::where('nama', $request->query('jenis'))->value('id') ?? '';
            }
            if ($statusAktif === '' && $request->filled('status')) {
                $statusAktif = match (strtolower((string) $request->query('status'))) {
                    'aktif' => 'Aktif', 'nonaktif', 'non-aktif' => 'Non-Aktif', 'pensiun' => 'Pensiun', 'mutasi' => 'Mutasi', default => ''
                };
            }
            if ($statusPegawaiId === '' && $statusAktif !== '') {
                $statusPegawaiId = RefStatusPegawai::where('nama', $statusAktif)->value('id') ?? '';
            }
            if ($request->query('filter') === 'pensiun' && $statusAktif === '') {
                $statusAktif = 'Pensiun';
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(nip) LIKE ?', ["%{$search}%"]);
                });
            }
            if ($golongan !== '') {
                $query->where('golongan_terakhir', 'like', $golongan.'%');
            }
            if ($unitKerjaId !== '') {
                $query->whereHas('positionHistories', function ($q) use ($unitKerjaId) {
                    $q->where('unit_kerja_id', $unitKerjaId)->where('is_latest', true);
                });
            }
            if ($jenisPegawaiId !== '') {
                $query->where('jenis_pegawai_id', $jenisPegawaiId);
            }
            if ($statusPegawaiId !== '') {
                $query->where('status_pegawai_id', $statusPegawaiId);
            } elseif ($statusAktif !== '') {
                $query->where('status_aktif', $statusAktif);
            }
        }

        $pegawaiData = $query->orderBy('nama_lengkap')->get();

        if ($requestedIds->isNotEmpty()) {
            $requestedOrder = $requestedIds->flip();
            $pegawaiData = $pegawaiData
                ->sortBy(fn (Employee $employee) => $requestedOrder[$employee->id] ?? PHP_INT_MAX)
                ->values();
        }

        $spreadsheet = $this->generateExcelSpreadsheet($pegawaiData);

        $filename = 'Data_Pegawai_SIMPEG_'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function generateExcelSpreadsheet($pegawaiData): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pegawai');
        $sheet->setShowGridlines(false);

        $cols = [
            'A' => ['No', 5],
            'B' => ['Nama Pegawai', 31],
            'C' => ['Email Pegawai', 29],
            'D' => ['Golongan', 12],
            'E' => ['Jabatan', 34],
            'F' => ['Kelas Jabatan', 15],
            'G' => ['NIP', 23],
            'H' => ['Nomor Telepon', 19],
            'I' => ['Pangkat', 18],
            'J' => ['Pendidikan Terakhir', 18],
            'K' => ['Pensiun', 20],
            'L' => ['Person', 22],
            'M' => ['Person Formula', 22],
            'N' => ['Prodi Pendidikan Terakhir', 28],
            'O' => ['Status Pegawai', 20],
            'P' => ['Tanggal Lahir', 20],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->getColumnDimension($col)->setWidth($width);
            $sheet->setCellValue($col.'1', $label);
        }
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->getStyle('A1:P1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Calibri'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5A83']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
        ]);

        foreach ($pegawaiData as $i => $employee) {
            $r = $i + 2;

            $sheet->setCellValue('A'.$r, $i + 1);
            $sheet->setCellValue('B'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('C'.$r, $employee->email_pribadi ?? '');
            $sheet->setCellValue('D'.$r, $employee->golongan_terakhir ?? '');
            $sheet->setCellValue('E'.$r, $employee->jabatan_terakhir ?? '');
            $sheet->setCellValue('F'.$r, $employee->kelas_jabatan_terakhir ?? '');
            $sheet->setCellValueExplicit('G'.$r, $employee->nip, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('H'.$r, $employee->no_hp ?? '', DataType::TYPE_STRING);
            $sheet->setCellValue('I'.$r, $employee->pangkat_terakhir ?? '');
            $sheet->setCellValue('J'.$r, $employee->pendidikan_terakhir ?? '');

            if ($employee->tanggal_pensiun !== null) {
                $sheet->setCellValue('K'.$r, Date::PHPToExcel($employee->tanggal_pensiun));
            }

            $sheet->setCellValue('L'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('M'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('N'.$r, $employee->prodi_pendidikan_terakhir ?? '');
            $sheet->setCellValue('O'.$r, $employee->statusPegawai?->nama ?? $employee->status_aktif ?? '');

            if ($employee->tanggal_lahir !== null) {
                $sheet->setCellValue('P'.$r, Date::PHPToExcel($employee->tanggal_lahir));
            }

            $sheet->getRowDimension($r)->setRowHeight(21);
            $sheet->getStyle('A'.$r.':P'.$r)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '111827']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9F2FB']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
            ]);
        }

        $lastRow = $pegawaiData->count() + 1;

        if ($pegawaiData->isNotEmpty()) {
            $sheet->getStyle('A2:A'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D2:D'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F2:K'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('O2:P'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K2:K'.$lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
            $sheet->getStyle('P2:P'.$lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:P'.$lastRow);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.3)->setRight(0.25)->setBottom(0.3)->setLeft(0.25);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);

        return $spreadsheet;
    }
}
