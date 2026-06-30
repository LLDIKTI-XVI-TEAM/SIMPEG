<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\EmployeeFamilies\CreateEmployeeFamilyAction;
use App\Actions\EmployeeFamilies\DeleteEmployeeFamilyAction;
use App\Actions\EmployeeFamilies\ListEmployeeFamiliesAction;
use App\Actions\EmployeeFamilies\UpdateEmployeeFamilyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeFamilyRequest;
use App\Http\Requests\UpdateEmployeeFamilyRequest;
use App\Models\Employee;
use App\Models\EmployeeFamily;
use App\Support\EmployeeFamilies\EmployeeFamilyPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class EmployeeFamilyController extends Controller
{
    public function index(Employee $employee, ListEmployeeFamiliesAction $action): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'families' => $action->execute($employee),
        ]);
    }

    public function store(
        StoreEmployeeFamilyRequest $request,
        Employee $employee,
        CreateEmployeeFamilyAction $action,
        EmployeeFamilyPayload $payload,
    ): JsonResponse {
        Log::info('Family payload:', $request->all());
        $family = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Data keluarga berhasil ditambahkan.',
            'family' => $payload->response($family),
        ], 201);
    }

    public function update(
        UpdateEmployeeFamilyRequest $request,
        Employee $employee,
        EmployeeFamily $family,
        UpdateEmployeeFamilyAction $action,
        EmployeeFamilyPayload $payload,
    ): JsonResponse {
        $family = $action->execute($employee, $family, $request->validated(), $request);

        return response()->json([
            'message' => 'Data keluarga berhasil diperbarui.',
            'family' => $payload->response($family),
        ]);
    }

    public function destroy(
        Employee $employee,
        EmployeeFamily $family,
        DeleteEmployeeFamilyAction $action,
    ): JsonResponse {
        $action->execute($employee, $family, request());

        return response()->json([
            'message' => 'Data keluarga berhasil dinonaktifkan.',
        ]);
    }
}
