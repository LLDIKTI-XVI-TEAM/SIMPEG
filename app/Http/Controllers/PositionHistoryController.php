<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePositionHistoryRequest;
use App\Models\Employee;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\JsonResponse;

class PositionHistoryController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $employee->positionHistories()
                ->with(['jenisJabatan', 'eselon', 'unitKerja'])
                ->orderByDesc('tmt_jabatan')
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function store(
        StorePositionHistoryRequest $request,
        Employee $employee,
        EmployeeHistoryService $service,
    ): JsonResponse {
        $history = $service->createPositionHistory($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat jabatan berhasil ditambahkan.',
            'history' => $history->load(['jenisJabatan', 'eselon', 'unitKerja']),
        ], 201);
    }
}
