<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\AssignSupervisorAction;
use App\Actions\Employees\CreateEmployeeAction;
use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\ListInactiveEmployeesAction;
use App\Actions\Employees\PrepareEmployeeEditFormDataAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Actions\Employees\UpdateEmployeeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Appointment;
use App\Models\Document;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PegawaiController extends Controller
{

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;

        $unitKerjaOptions = RefUnitKerja::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);
        $jenisPegawaiOptions = RefJenisPegawai::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);
        $statusOptions = ['Aktif', 'Non-Aktif', 'Pensiun', 'Mutasi'];
        $golonganOptions = Employee::query()
            ->whereNotNull('golongan_terakhir')
            ->distinct()
            ->orderBy('golongan_terakhir')
            ->pluck('golongan_terakhir')
            ->map(fn(?string $golongan) => $golongan ? strtok($golongan, '/') : null)
            ->filter()
            ->unique()
            ->values();

        if ($golonganOptions->isEmpty()) {
            $golonganOptions = collect(['II', 'III', 'IV']);
        }

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'golongan' => trim((string) $request->query('golongan', '')),
            'unit_kerja_id' => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
            'status_aktif' => trim((string) $request->query('status_aktif', '')),
        ];

        // Backward-compatible query params from the pagination branch.
        if ($filters['unit_kerja_id'] === '' && $request->filled('unit')) {
            $legacyUnit = (string) $request->query('unit');
            $matchedUnit = $unitKerjaOptions->firstWhere('nama', $legacyUnit);
            $filters['unit_kerja_id'] = $matchedUnit?->id ?? '';
        }

        if ($filters['jenis_pegawai_id'] === '' && $request->filled('jenis')) {
            $legacyJenis = (string) $request->query('jenis');
            $matchedJenis = $jenisPegawaiOptions->firstWhere('nama', $legacyJenis);
            $filters['jenis_pegawai_id'] = $matchedJenis?->id ?? '';
        }

        if ($filters['status_aktif'] === '' && $request->filled('status')) {
            $legacyStatus = strtolower((string) $request->query('status'));
            $filters['status_aktif'] = match ($legacyStatus) {
                'aktif' => 'Aktif',
                'nonaktif', 'non-aktif' => 'Non-Aktif',
                'pensiun' => 'Pensiun',
                'mutasi' => 'Mutasi',
                default => '',
            };
        }

        if ($request->query('filter') === 'pensiun' && $filters['status_aktif'] === '') {
            $filters['status_aktif'] = 'Pensiun';
        }

        if (!$unitKerjaOptions->contains('id', $filters['unit_kerja_id'])) {
            $filters['unit_kerja_id'] = '';
        }

        if (!$jenisPegawaiOptions->contains('id', $filters['jenis_pegawai_id'])) {
            $filters['jenis_pegawai_id'] = '';
        }

        if (!in_array($filters['status_aktif'], $statusOptions, true)) {
            $filters['status_aktif'] = '';
        }

        if ($filters['golongan'] !== '' && !$golonganOptions->contains($filters['golongan'])) {
            $filters['golongan'] = '';
        }

        $allowedSorts = ['pegawai', 'jabatan', 'golongan', 'tmt'];
        $sort = $request->query('sort', 'pegawai');
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'pegawai';

        $direction = strtolower((string) $request->query('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $pegawaiQuery = Employee::query()
            ->with([
                'jenisPegawai',
                'appointment',
                'positionHistories' => fn($query) => $query
                    ->with('unitKerja')
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan'),
            ]);

        if ($filters['search'] !== '') {
            $search = mb_strtolower($filters['search']);
            $pegawaiQuery->where(function ($query) use ($search): void {
                $query
                    ->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(nip) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($filters['golongan'] !== '') {
            $pegawaiQuery->where('golongan_terakhir', 'like', $filters['golongan'] . '%');
        }

        if ($filters['unit_kerja_id'] !== '') {
            $pegawaiQuery->whereHas('positionHistories', function ($query) use ($filters): void {
                $query
                    ->where('unit_kerja_id', $filters['unit_kerja_id'])
                    ->where('is_latest', true);
            });
        }

        if ($filters['jenis_pegawai_id'] !== '') {
            $pegawaiQuery->where('jenis_pegawai_id', $filters['jenis_pegawai_id']);
        }

        if ($filters['status_aktif'] !== '') {
            $pegawaiQuery->where('status_aktif', $filters['status_aktif']);
        }

        match ($sort) {
            'jabatan' => $pegawaiQuery
                ->orderBy('jabatan_terakhir', $direction)
                ->orderBy('nama_lengkap'),
            'golongan' => $pegawaiQuery
                ->orderBy('golongan_terakhir', $direction)
                ->orderBy('nama_lengkap'),
            'tmt' => $pegawaiQuery
                ->orderBy(
                    PositionHistory::query()
                        ->select('tmt_jabatan')
                        ->whereColumn('position_histories.employee_id', 'employees.id')
                        ->orderByDesc('is_latest')
                        ->orderByDesc('tmt_jabatan')
                        ->limit(1),
                    $direction
                )
                ->orderBy(
                    Appointment::query()
                        ->select('tmt_pengangkatan')
                        ->whereColumn('appointments.employee_id', 'employees.id')
                        ->orderBy('tmt_pengangkatan')
                        ->limit(1),
                    $direction
                )
                ->orderBy('nama_lengkap'),
            default => $pegawaiQuery
                ->orderBy('nama_lengkap', $direction)
                ->orderBy('nip'),
        };

        $pegawaiData = $pegawaiQuery
            ->paginate($perPage)
            ->withQueryString();

        // Data referensi untuk modal "Tambah Riwayat" langsung dari halaman daftar pegawai.
        $golonganRefOptions = RefGolongan::orderBy('kode')->get();
        $jenisJabatanOptions = RefJenisJabatan::orderBy('nama')->get();
        $eselonOptions = RefEselon::orderBy('nama')->get();

        return view('admin.pegawai.index', compact(
            'pegawaiData',
            'perPage',
            'sort',
            'direction',
            'filters',
            'golonganOptions',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
            'statusOptions',
            'golonganRefOptions',
            'jenisJabatanOptions',
            'eselonOptions'
        ));
    }

    public function create()
    {
        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        $unitKerja = RefUnitKerja::all();
        $jenisJabatanOptions = RefJenisJabatan::all();

        return view('admin.pegawai.create', compact('jenisPegawai', 'agama', 'statusKawin', 'unitKerja', 'jenisJabatanOptions'));
    }

    public function inactive(Request $request, ListInactiveEmployeesAction $action)
    {
        $unitKerjaOptions = RefUnitKerja::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);
        $jenisPegawaiOptions = RefJenisPegawai::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);
        $golonganOptions = Employee::query()
            ->whereNotNull('golongan_terakhir')
            ->distinct()
            ->orderBy('golongan_terakhir')
            ->pluck('golongan_terakhir')
            ->map(fn(?string $golongan) => $golongan ? strtok($golongan, '/') : null)
            ->filter()
            ->unique()
            ->values();

        if ($golonganOptions->isEmpty()) {
            $golonganOptions = collect(['II', 'III', 'IV']);
        }

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'golongan' => trim((string) $request->query('golongan', '')),
            'unit_kerja_id' => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
        ];

        $employees = $action->execute($filters);

        return view('admin.pegawai.nonaktif', compact(
            'employees',
            'filters',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
            'golonganOptions'
        ));
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action)
    {
        try {
            $employee = $action->execute($request->validated(), $request);

            return redirect()->route('data-pegawai')
                ->with('success', 'Data pegawai ' . $employee->nama_lengkap . ' berhasil ditambahkan.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal menambahkan pegawai: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        $p = Employee::with([
            'families',
            'rankHistories',
            'positionHistories',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories',
            'documents',
            'agama',
            'statusKawin',
            'jenisPegawai',
        ])->findOrFail($id);

        $golonganOptions = RefGolongan::all();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();

        return view('admin.pegawai.show', compact('p', 'golonganOptions', 'jenisJabatanOptions', 'unitKerjaOptions', 'eselonOptions'));
    }

    public function edit($id, PrepareEmployeeEditFormDataAction $action)
    {
        $data = $action->execute($id);

        return view('admin.pegawai.edit', $data);
    }

    public function update(UpdateEmployeeRequest $request, $id, UpdateEmployeeAction $action)
    {
        $employee = Employee::findOrFail($id);

        try {
            $employee = $action->execute($employee, $request->validated(), $request);

            return redirect()->route('data-pegawai')
                ->with('success', 'Data pegawai ' . $employee->nama_lengkap . ' berhasil diperbarui.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal memperbarui pegawai: ' . $e->getMessage());
        }
    }

    public function destroy($id, Request $request, DeactivateEmployeeAction $action)
    {
        $employee = Employee::findOrFail($id);
        $nama = $employee->nama_lengkap;

        $action->execute($employee, $request);

        return redirect()->route('data-pegawai')
            ->with('success', 'Data pegawai ' . $nama . ' berhasil dinonaktifkan.');
    }

    public function bulkDestroy(Request $request, DeactivateEmployeeAction $action)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data pegawai yang dipilih.');
        }

        try {
            DB::beginTransaction();

            $employees = Employee::whereIn('id', $ids)->get();
            $count = $employees->count();

            if ($count === 0) {
                DB::rollBack();

                return back()->with('error', 'Data pegawai tidak ditemukan.');
            }

            foreach ($employees as $employee) {
                $action->execute($employee, $request);
            }

            DB::commit();

            return back()->with('success', $count . ' pegawai berhasil dinonaktifkan.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Terjadi kesalahan saat menonaktifkan pegawai: ' . $e->getMessage());
        }
    }

    public function restore($id, Request $request, RestoreEmployeeAction $action)
    {
        $employee = Employee::onlyTrashed()->findOrFail($id);
        $nama = $employee->nama_lengkap;

        $action->execute($employee, $request);

        return redirect()->route('data-nonaktif')
            ->with('success', 'Data pegawai ' . $nama . ' berhasil diaktifkan kembali.');
    }

    public function storeRiwayat($id, Request $request, \App\Actions\Employees\StoreEmployeeHistoryAction $action)
    {
        $employee = Employee::findOrFail($id);

        try {
            $action->execute($employee, $request);

            return response()->json(['success' => true, 'message' => 'Data riwayat berhasil disimpan.']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * @return StreamedResponse
     */
    public function export(Request $request, \App\Actions\Employees\ExportEmployeeAction $action)
    {
        return $action->execute($request);
    }


    public function assignAtasan(Request $request, $id, AssignSupervisorAction $action)
    {
        $request->validate([
            'supervisor_id' => 'nullable|uuid|exists:employees,id',
        ]);

        $employee = Employee::findOrFail($id);

        try {
            $action->execute($employee, $request->input('supervisor_id'), $request);

            return redirect()->route('pegawai.show', $id)
                ->with('success', 'Atasan langsung untuk ' . $employee->nama_lengkap . ' berhasil diperbarui.');
        } catch (\Exception $e) {
            return redirect()->route('pegawai.show', $id)
                ->with('error', 'Gagal memperbarui atasan langsung: ' . $e->getMessage());
        }
    }
}
