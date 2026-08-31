<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreateKgbHistoryAction;
use App\Actions\Histories\ListKgbHistoriesAction;
use App\Actions\Histories\UploadKgbHistorySkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StoreKgbHistoryRequest;
use App\Http\Requests\History\UploadHistorySkRequest;
use App\Models\Employee;
use App\Models\SalaryHistory;
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

    public function uploadSk(
        UploadHistorySkRequest $request,
        Employee $employee,
        SalaryHistory $kgb,
        UploadKgbHistorySkAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $history = $action->execute($employee, $kgb, $request->file('file_sk'), $request);

        return response()->json([
            'message' => 'Berkas SK KGB berhasil diperbarui.',
            'history' => $payload->kgb($history, $employee),
        ]);
    }
}
