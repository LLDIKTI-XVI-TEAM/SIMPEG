<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Employees\AssignSupervisorAction;
use App\Actions\Employees\CreateEmployeeAction;
use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\DeleteEmployeeAction;
use App\Actions\Employees\ListEmployeesAction;
use App\Actions\Employees\ListInactiveEmployeesAction;
use App\Actions\Employees\PurgeDeletedEmployeesAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Actions\Employees\ShowEmployeeAction;
use App\Actions\Employees\ShowMyProfileAction;
use App\Actions\Employees\UpdateEmployeeAction;
use App\Actions\Employees\UpdateEmployeeStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\ListEmployeesRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(ListEmployeesRequest $request, ListEmployeesAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Daftar pegawai berhasil diambil.',
            'employees' => $action->execute($request->validated()),
        ]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): JsonResponse|RedirectResponse
    {
        $employee = $action->execute($request->validated(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Data pegawai berhasil ditambahkan.',
                'employee' => $employee,
            ], 201);
        }

        return back()->with('success', 'Data pegawai berhasil ditambahkan.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): JsonResponse|RedirectResponse
    {
        $employee = $action->execute($employee, $request->validated(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Data pegawai berhasil diperbarui.',
                'employee' => $employee,
            ]);
        }

        return back()->with('success', 'Data pegawai berhasil diperbarui.');
    }

    public function show(Employee $employee, ShowEmployeeAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Detail pegawai berhasil diambil.',
            'employee' => $action->execute($employee),
        ]);
    }

    public function inactive(Request $request, ListInactiveEmployeesAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Daftar pegawai nonaktif berhasil diambil.',
            'employees' => $action->execute($request->query())->through(fn (Employee $employee): array => $this->employeeListPayload($employee)),
        ]);
    }

    public function backup(Request $request): JsonResponse
    {
        $perPage = min(max((int) ($request->query('per_page', 10)), 1), 100);
        $search = trim((string) ($request->query('search', '')));
        $retentionDays = PurgeDeletedEmployeesAction::RETENTION_DAYS;

        $paginator = Employee::onlyTrashed()
            ->with([
                'jenisPegawai:id,nama',
                'positionHistories' => fn ($q) => $q
                    ->with(['jabatan:id,nama', 'unitKerja:id,nama'])
                    ->where('is_latest', true)
                    ->limit(1),
            ])
            ->when($search !== '', function ($q) use ($search): void {
                $keyword = '%'.mb_strtolower($search).'%';
                $q->where(function ($q) use ($keyword): void {
                    $q->whereRaw('LOWER(nama_lengkap) LIKE ?', [$keyword])
                        ->orWhereRaw('LOWER(nip) LIKE ?', [$keyword]);
                });
            })
            ->orderByDesc('deleted_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Employee $employee) => $this->backupPayload($employee, $retentionDays));

        $expiredCount = Employee::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays($retentionDays))
            ->count();

        return response()->json([
            'message' => 'Data backup pegawai berhasil diambil.',
            'employees' => $paginator,
            'expired_count' => $expiredCount,
            'retention_days' => $retentionDays,
        ]);
    }

    public function destroy(Employee $employee, Request $request, DeactivateEmployeeAction $action): JsonResponse
    {
        $action->execute($employee, $request);

        return response()->json([
            'message' => 'Data pegawai berhasil dinonaktifkan.',
        ]);
    }

    public function forceDestroy(Employee $employee, Request $request, DeleteEmployeeAction $action): JsonResponse
    {
        $action->execute($employee, $request);

        return response()->json([
            'message' => 'Data pegawai berhasil dihapus secara permanen.',
        ]);
    }

    public function updateStatus(Employee $employee, Request $request, UpdateEmployeeStatusAction $action): JsonResponse
    {
        $request->validate([
            'status' => 'required|string',
        ]);

        try {
            $updatedEmployee = $action->execute($employee, $request->status, $request);

            return response()->json([
                'message' => 'Status pegawai berhasil diperbarui.',
                'employee' => $this->employeeListPayload($updatedEmployee),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function restore(string $employee, Request $request, RestoreEmployeeAction $action): JsonResponse
    {
        $restored = $action->execute(Employee::onlyTrashed()->findOrFail($employee), $request);

        return response()->json([
            'message' => 'Data pegawai berhasil diaktifkan kembali.',
            'employee' => $this->employeeListPayload($restored),
        ]);
    }

    public function myProfile(Request $request, ShowMyProfileAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Detail profil pegawai berhasil diambil.',
            'employee' => $action->execute($request->user()?->employee),
        ]);
    }

    public function assignSupervisor(Request $request, Employee $employee, AssignSupervisorAction $action): JsonResponse
    {
        $request->validate([
            'kepala_bagian_id' => 'nullable|uuid|exists:employees,id',
            'supervisor_id' => 'nullable|uuid|exists:employees,id',
        ]);

        $updatedEmployee = $action->execute($employee, $request->input('kepala_bagian_id', $request->input('supervisor_id')), $request);

        return response()->json([
            'message' => 'Kepala bagian berhasil diperbarui.',
            'employee' => [
                'id' => $updatedEmployee->id,
                'nama_lengkap' => $updatedEmployee->nama_lengkap,
                'kepala_bagian' => $updatedEmployee->kepalaBagian ? [
                    'id' => $updatedEmployee->kepalaBagian->id,
                    'nama_lengkap' => $updatedEmployee->kepalaBagian->nama_lengkap,
                ] : null,

            ],
        ]);
    }

    /**
     * Payload ringkas untuk daftar nonaktif agar endpoint tidak mengekspos NIK/No KK dan data sensitif lain.
     *
     * @return array<string, mixed>
     */
    private function employeeListPayload(Employee $employee): array
    {
        $latestPosition = $employee->positionHistories->first();

        return [
            'id' => $employee->id,
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'jabatan_terakhir' => $employee->jabatan_terakhir,
            'golongan_terakhir' => $employee->golongan_terakhir,
            'jenis_pegawai' => $employee->jenisPegawai?->nama,
            'unit_kerja' => $latestPosition?->unitKerja?->nama,
            'deleted_at' => $employee->deleted_at,
        ];
    }

    /**
     * Payload untuk tabel backup — digunakan Alpine.js di halaman data-backup.
     *
     * @return array<string, mixed>
     */
    private function backupPayload(Employee $employee, int $retentionDays): array
    {
        $latestPosition = $employee->positionHistories->first();
        $deletedAt = $employee->deleted_at;
        $purgeAt = $deletedAt?->copy()->addDays($retentionDays);
        $sisaHari = $purgeAt ? (int) now()->diffInDays($purgeAt, false) : 0;

        return [
            'id' => $employee->id,
            'nama_lengkap' => $employee->nama_lengkap,
            'nip' => $employee->nip,
            'foto_url' => $employee->foto_url,
            'jabatan' => $latestPosition?->jabatan?->nama ?? $employee->jabatan_terakhir ?? '-',
            'unit_kerja' => $latestPosition?->unitKerja?->nama ?? '-',
            'golongan_terakhir' => $employee->golongan_terakhir ?? '-',
            'jenis_pegawai' => $employee->jenisPegawai?->nama ?? '-',
            'deleted_at_human' => $deletedAt?->format('d/m/Y H:i') ?? '-',
            'purge_at_human' => $purgeAt?->format('d/m/Y') ?? '-',
            'sisa_hari' => $sisaHari,
            'is_expired' => $sisaHari <= 0,
            'is_urgent' => $sisaHari > 0 && $sisaHari <= 3,
            'is_warning' => $sisaHari > 3 && $sisaHari <= 7,
        ];
    }
}
