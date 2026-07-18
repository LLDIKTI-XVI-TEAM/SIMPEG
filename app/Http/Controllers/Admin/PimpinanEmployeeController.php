<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ListEmployeesAction;
use App\Actions\Laporan\PimpinanCustomEmployeeExportAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Laporan\CustomEmployeeExportRequest;
use App\Http\Requests\Laporan\ExportPegawaiRequest;
use App\Models\Employee;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PimpinanEmployeeController extends Controller
{
    public function index(Request $request, ListEmployeesAction $listEmployees)
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'golongan' => trim((string) $request->query('golongan', '')),
            'unit_kerja_id' => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
            'status_pegawai_id' => trim((string) $request->query('status_pegawai_id', '')),
        ];
        $perPage = (int) $request->query('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;
        $sort = in_array($request->query('sort'), ['nama_lengkap', 'jabatan_terakhir', 'golongan_terakhir'], true)
            ? $request->query('sort')
            : 'nama_lengkap';
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        $employees = $listEmployees->execute(array_merge($filters, [
            'per_page' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
        ]));
        $unitKerjaOptions = RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']);
        $jenisPegawaiOptions = RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']);
        $statusOptions = RefStatusPegawai::query()->orderBy('nama')->get(['id', 'nama']);
        $golonganOptions = Employee::query()
            ->whereNotNull('golongan_terakhir')
            ->distinct()
            ->orderBy('golongan_terakhir')
            ->pluck('golongan_terakhir')
            ->map(fn (string $golongan): string => strtok($golongan, '/'))
            ->unique()
            ->values();

        $initialRows = $employees->items();
        $initialMeta = [
            'total' => $employees->total(),
            'current_page' => $employees->currentPage(),
            'last_page' => $employees->lastPage(),
            'from' => $employees->firstItem() ?? 0,
            'to' => $employees->lastItem() ?? 0,
            'per_page' => $employees->perPage(),
        ];

        $golonganOptions = Employee::query()
            ->whereNotNull('golongan_terakhir')
            ->distinct()
            ->orderBy('golongan_terakhir')
            ->pluck('golongan_terakhir')
            ->map(fn (?string $golongan) => $golongan ? strtok($golongan, '/') : null)
            ->filter()
            ->unique()
            ->values();

        if ($golonganOptions->isEmpty()) {
            $golonganOptions = collect(['II', 'III', 'IV']);
        }

        $unitKerjaOptions = RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']);
        $jenisPegawaiOptions = RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']);
        $statusOptions = RefStatusPegawai::query()->orderByDesc('is_default')->orderBy('nama')->get(['id', 'nama']);

        return view('admin.pegawai.index', compact(
            'initialRows', 'initialMeta',
            'golonganOptions', 'unitKerjaOptions', 'jenisPegawaiOptions', 'statusOptions',
            'employees',
            'filters',
            'perPage',
            'sort',
            'direction',
        ));
    }

    public function show(Employee $employee)
    {
        $employee->load([
            'agama',
            'jenisPegawai',
            'statusPegawai',
            'appointment',
            'rankHistories' => fn ($query) => $query->with('golongan')->orderByDesc('tmt_pangkat'),
            'positionHistories' => fn ($query) => $query->with(['jabatan', 'unitKerja'])->orderByDesc('tmt_jabatan'),
            'salaryHistories' => fn ($query) => $query->orderByDesc('tmt_kgb'),
            'disciplineRecords' => fn ($query) => $query->orderByDesc('tanggal_mulai'),
            'educationHistories' => fn ($query) => $query->with('jenjang')->orderByDesc('tahun_lulus'),
            'documents' => fn ($query) => $query->orderByDesc('tanggal_dokumen'),
            'families' => fn ($query) => $query
                ->select([
                    'id',
                    'employee_id',
                    'nama_anggota',
                    'hubungan',
                    'tempat_lahir',
                    'tanggal_lahir',
                    'jenis_kelamin',
                    'status_tunjangan',
                    'pekerjaan',
                ])
                ->orderBy('hubungan')
                ->orderBy('nama_anggota'),
            'supervisorAssignments' => fn ($query) => $query
                ->select(['id', 'employee_id', 'supervisor_id', 'kepala_bagian_id', 'tanggal_mulai', 'tanggal_berakhir'])
                ->whereNull('tanggal_berakhir')
                ->with([
                    'supervisor:id,nama_lengkap,jabatan_terakhir',
                    'supervisor.positionHistories' => fn ($positions) => $positions
                        ->select(['id', 'employee_id', 'unit_kerja_id', 'nama_jabatan', 'is_latest'])
                        ->where('is_latest', true)
                        ->with('unitKerja:id,nama'),
                ]),
        ]);

        $golonganOptions = RefGolongan::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();
        $jenjangOptions = RefJenjangPendidikan::orderBy('urutan')->get();

        $p = $employee;

        return view('pimpinan.pegawai.show', compact('p'));
    }

    public function reportPage(ExportPegawaiRequest $request): mixed
    {
        $filters         = $request->validated();
        $selectedColumns = (array) ($filters['columns'] ?? []);

        return view('pimpinan.laporan.pegawai', [
            'filters'        => $filters,
            'selectedColumns' => $selectedColumns,
            'allowedColumns'  => PimpinanCustomEmployeeExportAction::ALLOWED_COLUMNS,
        ]);
    }

    public function reportCustom(CustomEmployeeExportRequest $request, PimpinanCustomEmployeeExportAction $action): StreamedResponse
    {
        return $action->execute($request->validated());
    }
}
