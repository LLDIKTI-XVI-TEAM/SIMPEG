<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ListEmployeesAction;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Http\Request;

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

        return view('pimpinan.pegawai.index', compact(
            'employees',
            'filters',
            'perPage',
            'sort',
            'direction',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
            'statusOptions',
            'golonganOptions',
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
                        ->select(['id', 'positions.employee_id', 'positions.unit_kerja_id', 'nama_jabatan', 'is_latest'])
                        ->where('is_latest', true)
                        ->with('unitKerja:id,nama'),
                ]),
        ]);

        return view('pimpinan.pegawai.show', compact('employee'));
    }
}
