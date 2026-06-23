<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKgbHistoryRequest;
use App\Models\Employee;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\JsonResponse;

class KgbHistoryController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $employee->salaryHistories()
                ->orderByDesc('tmt_kgb')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(
        StoreKgbHistoryRequest $request,
        Employee $employee,
        EmployeeHistoryService $service,
    ): JsonResponse {
        $history = $service->createKgbHistory($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat KGB berhasil ditambahkan.',
            'history' => $history,
        ], 201);
    }
}
