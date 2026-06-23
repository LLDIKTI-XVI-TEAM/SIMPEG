<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListEmployeesRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

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
