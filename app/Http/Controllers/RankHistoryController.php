<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRankHistoryRequest;
use App\Models\Employee;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\JsonResponse;

class RankHistoryController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $employee->rankHistories()
                ->with('golongan')
                ->orderByDesc('tmt_pangkat')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(
        StoreRankHistoryRequest $request,
        Employee $employee,
        EmployeeHistoryService $service,
    ): JsonResponse {
        $history = $service->createRankHistory($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat kepangkatan berhasil ditambahkan.',
            'history' => $history->load('golongan'),
        ], 201);
    }
}
