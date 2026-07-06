<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\EmployeeFamilies\CreateEmployeeFamilyAction;
use App\Actions\EmployeeFamilies\ListEmployeeFamiliesAction;
use App\Actions\EmployeeFamilies\UpdateEmployeeFamilyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreMyFamilyRequest;
use App\Http\Requests\Employee\UpdateMyFamilyRequest;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Support\EmployeeFamilies\EmployeeFamilyPayload;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint self-service data keluarga khusus role pegawai.
 *
 * Employee selalu di-resolve dari user yang sedang login (auth()->user()->employee),
 * sehingga pegawai tidak dapat mengakses atau mengubah data keluarga pegawai lain.
 */
class MyFamilyController extends Controller
{
    public function index(ListEmployeeFamiliesAction $action): JsonResponse
    {
        $employee = $this->resolveEmployee();

        return response()->json([
            'employee_id' => $employee->id,
            'families'    => $action->execute($employee),
        ]);
    }

    public function store(
        StoreMyFamilyRequest $request,
        CreateEmployeeFamilyAction $action,
        EmployeeFamilyPayload $payload,
    ): JsonResponse {
        $employee = $this->resolveEmployee();

        $family = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Data keluarga berhasil ditambahkan.',
            'family'  => $payload->response($family),
        ], 201);
    }

    public function update(
        UpdateMyFamilyRequest $request,
        EmployeeFamily $family,
        UpdateEmployeeFamilyAction $action,
        EmployeeFamilyPayload $payload,
    ): JsonResponse {
        $employee = $this->resolveEmployee();

        // Pastikan record keluarga yang diedit benar-benar milik pegawai yang sedang login.
        abort_unless($family->employee_id === $employee->id, 403, 'Anda hanya dapat mengubah data keluarga milik Anda sendiri.');

        $family = $action->execute($employee, $family, $request->validated(), $request);

        return response()->json([
            'message' => 'Data keluarga berhasil diperbarui.',
            'family'  => $payload->response($family),
        ]);
    }

    /**
     * Mengambil model Employee dari user yang sedang login.
     * Guard sudah memastikan user login dan employee_id tidak null (via StoreMyFamilyRequest::authorize).
     */
    private function resolveEmployee(): Employee
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return Employee::findOrFail($user->employee_id);
    }
}
