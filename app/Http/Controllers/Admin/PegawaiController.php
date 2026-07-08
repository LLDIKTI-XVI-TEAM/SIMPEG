<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\AssignSupervisorAction;
use App\Actions\Employees\CreateEmployeeAction;
use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\ExportEmployeeAction;
use App\Actions\Employees\ListEmployeesAction;
use App\Actions\Employees\ListInactiveEmployeesAction;
use App\Actions\Employees\PrepareEmployeeEditFormDataAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Actions\Employees\StoreEmployeeHistoryAction;
use App\Actions\Employees\UpdateEmployeeAction;
use App\Actions\Employees\UpdateEmployeePerformanceFlagAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeePerformanceFlagRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PegawaiController extends Controller
{
    public function index(Request $request, ListEmployeesAction $listAction)
    {
        $perPage = (int) $request->query('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;

        // === Data Referensi — di-cache agar tidak query DB setiap request ===
        $unitKerjaOptions = Cache::remember('ref.unit_kerja', now()->addHours(6), function () {
            return RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']);
        });
        $jenisPegawaiOptions = Cache::remember('ref.jenis_pegawai', now()->addHours(6), function () {
            return RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']);
        });
        $statusOptions = Cache::remember('ref.status_pegawai', now()->addHours(6), function () {
            return RefStatusPegawai::query()->orderByDesc('is_default')->orderBy('nama')->get(['id', 'nama']);
        });
        $golonganOptions = Cache::remember('ref.golongan_distinct', now()->addHours(1), function () {
            $opts = Employee::query()
                ->whereNotNull('golongan_terakhir')
                ->distinct()
                ->orderBy('golongan_terakhir')
                ->pluck('golongan_terakhir')
                ->map(fn (?string $golongan) => $golongan ? strtok($golongan, '/') : null)
                ->filter()
                ->unique()
                ->values();

            return $opts->isEmpty() ? collect(['II', 'III', 'IV']) : $opts;
        });
        $golonganRefOptions = Cache::remember('ref.golongan', now()->addHours(6), function () {
            return RefGolongan::orderBy('kode')->get();
        });
        $jabatanOptions = Cache::remember('ref.jabatan_with_jenis', now()->addHours(6), function () {
            return RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        });
        $jenisJabatanOptions = Cache::remember('ref.jenis_jabatan', now()->addHours(6), function () {
            return RefJenisJabatan::orderBy('nama')->get();
        });
        $eselonOptions = Cache::remember('ref.eselon', now()->addHours(6), function () {
            return RefEselon::orderBy('nama')->get();
        });

        $filters = [
            'search'           => trim((string) $request->query('search', '')),
            'golongan'         => trim((string) $request->query('golongan', '')),
            'unit_kerja_id'    => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
            'status_pegawai_id' => trim((string) $request->query('status_pegawai_id', 'all')),
            'status_aktif'     => trim((string) $request->query('status_aktif', '')),
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

        if (($filters['status_pegawai_id'] === '' || $filters['status_pegawai_id'] === 'all') && $filters['status_aktif'] !== '') {
            $filters['status_pegawai_id'] = $statusOptions->firstWhere('nama', $filters['status_aktif'])?->id ?? 'all';
        }

        if ($request->query('filter') === 'pensiun' && $filters['status_aktif'] === '') {
            $filters['status_aktif'] = 'Pensiun';
        }

        if (! $unitKerjaOptions->contains('id', $filters['unit_kerja_id'])) {
            $filters['unit_kerja_id'] = '';
        }
        if (! $jenisPegawaiOptions->contains('id', $filters['jenis_pegawai_id'])) {
            $filters['jenis_pegawai_id'] = '';
        }
        if ($filters['status_pegawai_id'] !== 'all' && ! $statusOptions->contains('id', $filters['status_pegawai_id'])) {
            $filters['status_pegawai_id'] = '';
        }
        if ($filters['status_aktif'] !== '' && ! $statusOptions->contains('nama', $filters['status_aktif'])) {
            $filters['status_aktif'] = '';
        }
        if ($filters['golongan'] !== '' && ! $golonganOptions->contains($filters['golongan'])) {
            $filters['golongan'] = '';
        }

        $allowedSorts = ['nama_lengkap', 'jabatan_terakhir', 'golongan_terakhir', 'created_at'];
        $sort = $request->query('sort', 'nama_lengkap');
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'nama_lengkap';

        $direction = strtolower((string) $request->query('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        // === Initial page data — menggunakan ListEmployeesAction (sama seperti API) ===
        $validated = array_merge($filters, [
            'sort'      => $sort,
            'direction' => $direction,
            'per_page'  => $perPage,
        ]);

        $paginator   = $listAction->execute($validated);
        $initialRows = $paginator->items(); // sudah berupa flat array dari ->through()
        $initialMeta = [
            'total'        => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'from'         => $paginator->firstItem() ?? 0,
            'to'           => $paginator->lastItem() ?? 0,
            'per_page'     => $paginator->perPage(),
        ];

        return view('admin.pegawai.index', compact(
            'perPage',
            'sort',
            'direction',
            'filters',
            'initialRows',
            'initialMeta',
            'golonganOptions',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
            'statusOptions',
            'golonganRefOptions',
            'jabatanOptions',
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
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $statusPegawai = RefStatusPegawai::orderByDesc('is_default')->orderBy('nama')->get();

        return view('admin.pegawai.create', compact('jenisPegawai', 'agama', 'statusKawin', 'unitKerja', 'jabatanOptions', 'jenisJabatanOptions', 'statusPegawai'));
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
            ->map(fn (?string $golongan) => $golongan ? strtok($golongan, '/') : null)
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
                ->with('success', 'Data pegawai '.$employee->nama_lengkap.' berhasil ditambahkan.')
                ->with('employee_data_changed', true);
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal menambahkan pegawai: '.$e->getMessage());
        }
    }

    public function show($id)
    {
        $p = Employee::with([
            'families',
            'rankHistories',
            'positionHistories.jabatan',
            'positionHistories.unitKerja',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories',
            'documents',
            'agama',
            'statusKawin',
            'jenisPegawai',
            'statusPegawai',
            'supervisorAssignments.supervisor',
        ])->findOrFail($id);

        $golonganOptions = RefGolongan::all();
        $jabatanOptions = RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();

        return view('admin.pegawai.show', compact('p', 'golonganOptions', 'jabatanOptions', 'jenisJabatanOptions', 'unitKerjaOptions', 'eselonOptions'));
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
                ->with('success', 'Data pegawai '.$employee->nama_lengkap.' berhasil diperbarui.')
                ->with('employee_data_changed', true);
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Gagal memperbarui pegawai: '.$e->getMessage());
        }
    }

    public function destroy($id, Request $request, DeactivateEmployeeAction $action)
    {
        $employee = Employee::findOrFail($id);
        $nama = $employee->nama_lengkap;

        $action->execute($employee, $request);

        return redirect()->route('data-pegawai')
            ->with('success', 'Data pegawai '.$nama.' berhasil dinonaktifkan.')
            ->with('employee_data_changed', true);
    }

    /**
     * Memperbarui flag kinerja manual yang menjadi pengganti SKP sementara untuk eligibility EWS.
     */
    public function updatePerformanceFlag(
        UpdateEmployeePerformanceFlagRequest $request,
        string $id,
        UpdateEmployeePerformanceFlagAction $action,
    ): JsonResponse {
        $employee = Employee::findOrFail($id);

        $updated = $action->execute(
            $employee,
            $request->boolean('is_kinerja_baik'),
            $request,
        );

        return response()->json([
            'message' => 'Status kinerja pegawai berhasil diperbarui.',
            'is_kinerja_baik' => $updated->is_kinerja_baik,
        ]);
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

            return back()
                ->with('success', $count.' pegawai berhasil dinonaktifkan.')
                ->with('employee_data_changed', true);
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Terjadi kesalahan saat menonaktifkan pegawai: '.$e->getMessage());
        }
    }

    public function restore($id, Request $request, RestoreEmployeeAction $action)
    {
        $employee = Employee::onlyTrashed()->findOrFail($id);
        $nama = $employee->nama_lengkap;

        $action->execute($employee, $request);

        return redirect()->route('data-nonaktif')
            ->with('success', 'Data pegawai '.$nama.' berhasil diaktifkan kembali.');
    }

    public function storeRiwayat($id, Request $request, StoreEmployeeHistoryAction $action)
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
    public function export(Request $request, ExportEmployeeAction $action)
    {
        return $action->execute($request);
    }

    public function assignAtasan(Request $request, $id, AssignSupervisorAction $action)
    {
        $request->validate([
            'kepala_bagian_id' => 'nullable|uuid|exists:employees,id',
            'supervisor_id' => 'nullable|uuid|exists:employees,id',
        ]);

        $employee = Employee::findOrFail($id);

        try {
            $action->execute($employee, $request->input('kepala_bagian_id', $request->input('supervisor_id')), $request);

            return redirect()->route('pegawai.show', $id)
                ->with('success', 'Kepala bagian untuk '.$employee->nama_lengkap.' berhasil diperbarui.')
                ->with('employee_data_changed', true);
        } catch (\Exception $e) {
            return redirect()->route('pegawai.show', $id)
                ->with('error', 'Gagal memperbarui kepala bagian: '.$e->getMessage());
        }
    }
}
