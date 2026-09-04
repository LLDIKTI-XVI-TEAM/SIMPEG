<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use App\Models\User;
use App\Services\Employees\KepalaBagianScopeService;
use App\Support\Laporan\ExcelStyleHelper;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportEmployeeAction
{
    /**
     * Mengekspor data pegawai menjadi file Excel dan mengembalikannya sebagai StreamedResponse.
     */
    public function execute(Request $request): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $effectiveRole = $actor->getEffectiveRole();
        abort_unless(in_array($effectiveRole, ['super_admin', 'admin_kepegawaian', 'pimpinan', 'kepala_bagian', 'pegawai'], true), 403);

        // Pimpinan menerima ekspor lengkap seluruh organisasi sebagai pengecualian
        // stakeholder. Kepala Bagian dan Pegawai selalu memakai scope serta kolom aman.
        $masked = in_array($effectiveRole, ['kepala_bagian', 'pegawai'], true);
        $requestedIds = collect($request->input('ids', []))
            ->filter(fn ($id) => is_string($id) && trim($id) !== '')
            ->map(fn (string $id) => trim($id))
            ->unique()
            ->values();

        $query = Employee::query()->with([
            'jenisPegawai:id,nama',
            'statusPegawai:id,nama',
            'programStudi:id,nama',
            'positionHistories' => fn ($positionQuery) => $positionQuery
                ->select(['id', 'employee_id', 'unit_kerja_id', 'is_latest', 'tmt_jabatan'])
                ->where('is_latest', true)
                ->with('unitKerja:id,nama'),
        ]);

        if ($effectiveRole === 'kepala_bagian') {
            $query->whereIn('employees.id', app(KepalaBagianScopeService::class)->directReportIds($actor));
        } elseif ($effectiveRole === 'pegawai') {
            $query->whereKey($actor->employee_id ?? '');
        }

        if ($requestedIds->isNotEmpty()) {
            if ($masked) {
                $scopedIds = (clone $query)->whereIn('employees.id', $requestedIds->all())->pluck('employees.id');
                abort_unless($scopedIds->count() === $requestedIds->count(), 403);
            }

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
            // Normalisasi "all" sama seperti daftar: pilihan eksplisit "semua status"
            // tidak boleh jatuh ke default aktif.
            if ($statusPegawaiId === '' && mb_strtolower($statusAktif) === 'all') {
                $statusAktif = '';
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
            } else {
                // Tanpa filter status apa pun: default hanya pegawai aktif menurut
                // klasifikasi kelompok referensi — satu sumber dengan daftar
                // (ListEmployeesAction) dan isActive(), sehingga ekspor tidak
                // menyertakan pegawai nonaktif yang tak terlihat di layar.
                $query->whereActiveStatus();
            }
        }

        $pegawaiData = $query->orderBy('nama_lengkap')->get();

        if ($requestedIds->isNotEmpty()) {
            $requestedOrder = $requestedIds->flip();
            $pegawaiData = $pegawaiData
                ->sortBy(fn (Employee $employee) => $requestedOrder[$employee->id] ?? PHP_INT_MAX)
                ->values();
        }

        $spreadsheet = $this->generateExcelSpreadsheet($pegawaiData, $masked);

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

    private function generateExcelSpreadsheet($pegawaiData, bool $masked): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pegawai');
        $sheet->setShowGridlines(false);

        $cols = $masked ? [
            'A' => ['No', 5],
            'B' => ['Nama Pegawai', 31],
            'C' => ['Unit Kerja', 30],
            'D' => ['Golongan', 12],
            'E' => ['Jabatan', 34],
            'F' => ['Jenis Pegawai', 20],
            'G' => ['Status Pegawai', 20],
        ] : [
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
        $lastColumn = array_key_last($cols);
        ExcelStyleHelper::applyHeaderStyle($sheet, 'A1:'.$lastColumn.'1');

        foreach ($pegawaiData as $i => $employee) {
            $r = $i + 2;

            $sheet->setCellValue('A'.$r, $i + 1);
            $sheet->setCellValue('B'.$r, $employee->nama_lengkap);

            if ($masked) {
                $sheet->setCellValue('C'.$r, $employee->positionHistories->first()?->unitKerja?->nama ?? '-');
                $sheet->setCellValue('D'.$r, $employee->golongan_terakhir ?? '');
                $sheet->setCellValue('E'.$r, $employee->jabatan_terakhir ?? '');
                $sheet->setCellValue('F'.$r, $employee->jenisPegawai?->nama ?? '');
                $sheet->setCellValue('G'.$r, $employee->statusPegawai?->nama ?? $employee->status_aktif ?? '');

                continue;
            }

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
            $sheet->setCellValue('N'.$r, $employee->programStudi?->nama ?? $employee->prodi_pendidikan_terakhir ?? '');
            $sheet->setCellValue('O'.$r, $employee->statusPegawai?->nama ?? $employee->status_aktif ?? '');

            if ($employee->tanggal_lahir !== null) {
                $sheet->setCellValue('P'.$r, Date::PHPToExcel($employee->tanggal_lahir));
            }

        }

        $lastRow = $pegawaiData->count() + 1;

        if ($pegawaiData->isNotEmpty()) {
            ExcelStyleHelper::applyRowStyle($sheet, 'A2:'.$lastColumn.$lastRow);
            $sheet->getStyle('A2:A'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            if (! $masked) {
                $sheet->getStyle('D2:D'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('F2:K'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('O2:P'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('K2:K'.$lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
                $sheet->getStyle('P2:P'.$lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
            }
        }

        ExcelStyleHelper::applyGlobalSetup($sheet, 'A1:'.$lastColumn.$lastRow);

        return $spreadsheet;
    }
}
