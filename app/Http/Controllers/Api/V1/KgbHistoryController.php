<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreateKgbHistoryAction;
use App\Actions\Histories\ListKgbHistoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StoreKgbHistoryRequest;
use App\Models\Employee;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Http\JsonResponse;

class KgbHistoryController extends Controller
{
    public function index(
        Employee $employee,
        ListKgbHistoriesAction $action,
    ): JsonResponse {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $action->execute($employee),
        ]);
    }

    public function store(
        StoreKgbHistoryRequest $request,
        Employee $employee,
        CreateKgbHistoryAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $history = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat KGB berhasil ditambahkan.',
            'history' => $payload->kgb($history, $employee),
        ], 201);
    }
}
