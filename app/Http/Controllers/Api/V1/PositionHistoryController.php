<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreatePositionHistoryAction;
use App\Actions\Histories\ListPositionHistoriesAction;
use App\Actions\Histories\UploadPositionHistorySkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StorePositionHistoryRequest;
use App\Http\Requests\History\UploadHistorySkRequest;
use App\Models\Employee;
use App\Models\PositionHistory;
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

    public function uploadSk(
        UploadHistorySkRequest $request,
        Employee $employee,
        PositionHistory $position,
        UploadPositionHistorySkAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $history = $action->execute($employee, $position, $request->file('file_sk'), $request);

        return response()->json([
            'message' => 'Berkas SK jabatan berhasil diperbarui.',
            'history' => $payload->position($history, $employee),
        ]);
    }
}
