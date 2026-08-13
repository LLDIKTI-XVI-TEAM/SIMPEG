<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreateRankHistoryAction;
use App\Actions\Histories\ListRankHistoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StoreRankHistoryRequest;
use App\Models\Employee;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Http\JsonResponse;

class RankHistoryController extends Controller
{
    public function index(
        Employee $employee,
        ListRankHistoriesAction $action,
    ): JsonResponse {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $action->execute($employee),
        ]);
    }

    public function store(
        StoreRankHistoryRequest $request,
        Employee $employee,
        CreateRankHistoryAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $history = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat kepangkatan berhasil ditambahkan.',
            'history' => $payload->rank($history, $employee),
        ], 201);
    }
}
