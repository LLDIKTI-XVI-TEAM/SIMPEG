<?php

namespace App\Http\Controllers;

use App\Actions\Histories\CreateRankHistoryAction;
use App\Actions\Histories\ListRankHistoriesAction;
use App\Http\Requests\StoreRankHistoryRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class RankHistoryController extends Controller
{
    public function index(Employee $employee, ListRankHistoriesAction $action): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $action->execute($employee),
        ]);
    }

    public function store(
        StoreRankHistoryRequest $request,
        Employee $employee,
        CreateRankHistoryAction $action,
    ): JsonResponse {
        $history = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat kepangkatan berhasil ditambahkan.',
            'history' => $history,
        ], 201);
    }
}
