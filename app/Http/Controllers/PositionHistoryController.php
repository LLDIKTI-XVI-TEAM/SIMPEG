<?php

namespace App\Http\Controllers;

use App\Actions\Histories\CreatePositionHistoryAction;
use App\Actions\Histories\ListPositionHistoriesAction;
use App\Http\Requests\StorePositionHistoryRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class PositionHistoryController extends Controller
{
    public function index(Employee $employee, ListPositionHistoriesAction $action): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $action->execute($employee),
        ]);
    }

    public function store(
        StorePositionHistoryRequest $request,
        Employee $employee,
        CreatePositionHistoryAction $action,
    ): JsonResponse {
        $history = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat jabatan berhasil ditambahkan.',
            'history' => $history,
        ], 201);
    }
}
