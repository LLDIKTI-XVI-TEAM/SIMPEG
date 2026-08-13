<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreatePositionHistoryAction;
use App\Actions\Histories\ListPositionHistoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StorePositionHistoryRequest;
use App\Models\Employee;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Http\JsonResponse;

class PositionHistoryController extends Controller
{
    public function index(
        Employee $employee,
        ListPositionHistoriesAction $action,
    ): JsonResponse {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $action->execute($employee),
        ]);
    }

    public function store(
        StorePositionHistoryRequest $request,
        Employee $employee,
        CreatePositionHistoryAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $history = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat jabatan berhasil ditambahkan.',
            'history' => $payload->position($history, $employee),
        ], 201);
    }
}
