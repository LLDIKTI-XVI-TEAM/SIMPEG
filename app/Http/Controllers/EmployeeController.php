<?php

namespace App\Http\Controllers;

use App\Actions\Employees\AssignSupervisorAction;
use App\Actions\Employees\CreateEmployeeAction;
use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\ListEmployeesAction;
use App\Actions\Employees\ListInactiveEmployeesAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Actions\Employees\ShowEmployeeAction;
use App\Actions\Employees\ShowMyProfileAction;
use App\Actions\Employees\UpdateEmployeeAction;
use App\Http\Requests\ListEmployeesRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
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

    public function destroy(Employee $employee, Request $request, DeactivateEmployeeAction $action): JsonResponse
    {
        $action->execute($employee, $request);

        return response()->json([
            'message' => 'Data pegawai berhasil dinonaktifkan.',
        ]);
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
            'supervisor_id' => 'nullable|uuid|exists:employees,id',
        ]);

        $updatedEmployee = $action->execute($employee, $request->input('supervisor_id'), $request);

        return response()->json([
            'message' => 'Atasan langsung berhasil diperbarui.',
            'employee' => [
                'id' => $updatedEmployee->id,
                'nama_lengkap' => $updatedEmployee->nama_lengkap,
                'atasan_langsung' => $updatedEmployee->atasanLangsung ? [
                    'id' => $updatedEmployee->atasanLangsung->id,
                    'nama_lengkap' => $updatedEmployee->atasanLangsung->nama_lengkap,
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
}
