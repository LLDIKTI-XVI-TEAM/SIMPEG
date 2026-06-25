<?php

namespace App\Http\Controllers;

use App\Actions\Histories\CreateKgbHistoryAction;
use App\Actions\Histories\ListKgbHistoriesAction;
use App\Http\Requests\StoreKgbHistoryRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class KgbHistoryController extends Controller
{
    public function index(Employee $employee, ListKgbHistoriesAction $action): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $action->execute($employee),
        ]);
    }

    public function store(
        StoreKgbHistoryRequest $request,
        Employee $employee,
        CreateKgbHistoryAction $action,
    ): JsonResponse {
        $history = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat KGB berhasil ditambahkan.',
            'history' => $history,
        ], 201);
    }
}
