<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListEmployeesRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

class EmployeeController extends Controller
{
    public function index(ListEmployeesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $sort = $validated['sort'] ?? 'nama_lengkap';
        $direction = $validated['direction'] ?? 'asc';
        $perPage = (int) ($validated['per_page'] ?? 10);

        $employees = Employee::query()
            ->select([
                'id',
                'nama_lengkap',
                'nip',
                'email',
                'golongan_terakhir',
                'pangkat_terakhir',
                'jabatan_terakhir',
                'kelas_jabatan',
                'jenis_pegawai_id',
                'status_aktif',
                'foto',
                'created_at',
            ])
            ->with(['jenisPegawai:id,nama'])
            ->when(
                $validated['search'] ?? null,
                fn ($query, string $search) => $query->where(function ($query) use ($search): void {
                    $keyword = '%'.mb_strtolower($search).'%';

                    $query->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                        ->orWhereRaw('lower(nip) like ?', [$keyword]);
                })
            )
            ->when(
                $validated['golongan'] ?? null,
                fn ($query, string $golongan) => $query->where('golongan_terakhir', $golongan)
            )
            ->when(
                $validated['jenis_pegawai_id'] ?? null,
                fn ($query, string $jenisPegawaiId) => $query->where('jenis_pegawai_id', $jenisPegawaiId)
            )
            ->when(
                $validated['status_aktif'] ?? null,
                fn ($query, string $statusAktif) => $query->where('status_aktif', $statusAktif),
                fn ($query) => $query->where('status_aktif', 'Aktif')
            )
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'message' => 'Daftar pegawai berhasil diambil.',
            'employees' => $employees,
        ]);
    }

    public function show(Employee $employee): JsonResponse
    {
        $employee->load([
            'jenisPegawai',
            'rankHistories' => fn ($query) => $query->with('golongan')->orderByDesc('tmt_pangkat')->orderByDesc('created_at'),
            'positionHistories' => fn ($query) => $query->with(['jenisJabatan', 'eselon', 'unitKerja'])->orderByDesc('tmt_jabatan')->orderByDesc('created_at'),
            'salaryHistories' => fn ($query) => $query->orderByDesc('tmt_kgb')->orderByDesc('created_at'),
        ]);

        return response()->json([
            'message' => 'Detail pegawai berhasil diambil.',
            'employee' => $this->employeeDetailPayload($employee),
        ]);
    }

    /**
     * Membatasi data detail pegawai agar field sensitif dan riwayat khusus tidak bocor lewat endpoint umum.
     */
    private function employeeDetailPayload(Employee $employee): array
    {
        return [
            ...Arr::only($employee->toArray(), [
                'id',
                'nama_lengkap',
                'nip',
                'tempat_lahir',
                'tanggal_lahir',
                'jenis_kelamin',
                'golongan_darah',
                'foto',
                'jenis_pegawai_id',
                'status_aktif',
                'golongan_terakhir',
                'pangkat_terakhir',
                'jabatan_terakhir',
                'kelas_jabatan',
                'pendidikan_terakhir',
                'prodi_pendidikan_terakhir',
                'tanggal_pensiun',
                'tanggal_kenaikan_pangkat_berikutnya',
                'tanggal_kgb_berikutnya',
                'profil_status',
                'email',
                'is_kinerja_baik',
                'created_at',
                'updated_at',
            ]),
            'jenis_pegawai' => $employee->jenisPegawai,
            'rank_histories' => $employee->rankHistories,
            'position_histories' => $employee->positionHistories,
            'salary_histories' => $employee->salaryHistories,
        ];
    }

    public function store(StoreEmployeeRequest $request): JsonResponse|RedirectResponse
    {
        $employee = Employee::create($request->validated());

        AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->toArray(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Data pegawai berhasil ditambahkan.',
                'employee' => $employee,
            ], 201);
        }

        return back()->with('success', 'Data pegawai berhasil ditambahkan.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse|RedirectResponse
    {
        $oldValues = $employee->toArray();

        $employee->update($request->validated());
        $employee->refresh();

        AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->toArray(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Data pegawai berhasil diperbarui.',
                'employee' => $employee,
            ]);
        }

        return back()->with('success', 'Data pegawai berhasil diperbarui.');
    }
}
