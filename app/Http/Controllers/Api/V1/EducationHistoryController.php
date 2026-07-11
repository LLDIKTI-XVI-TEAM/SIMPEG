<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreateEducationHistoryAction;
use App\Actions\Histories\DeleteEducationHistoryAction;
use App\Actions\Histories\ListEducationHistoriesAction;
use App\Actions\Histories\UpdateEducationHistoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StoreEducationHistoryRequest;
use App\Http\Requests\History\UpdateEducationHistoryRequest;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Support\Histories\EducationHistoryPayload;
use Illuminate\Http\JsonResponse;

class EducationHistoryController extends Controller
{
    public function __construct(private readonly EducationHistoryPayload $payload) {}

    public function index(Employee $employee, ListEducationHistoriesAction $action): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'histories'   => $action->execute($employee),
        ]);
    }

    public function store(
        StoreEducationHistoryRequest $request,
        Employee $employee,
        CreateEducationHistoryAction $action,
    ): JsonResponse {
        $history = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat pendidikan berhasil ditambahkan.',
            'history' => $this->payload->response($history),
        ], 201);
    }

    public function update(
        UpdateEducationHistoryRequest $request,
        Employee $employee,
        EducationHistory $education,
        UpdateEducationHistoryAction $action,
    ): JsonResponse {
        $history = $action->execute($employee, $education, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat pendidikan berhasil diperbarui.',
            'history' => $this->payload->response($history),
        ]);
    }

    public function destroy(
        Employee $employee,
        EducationHistory $education,
        DeleteEducationHistoryAction $action,
    ): JsonResponse {
        $action->execute($employee, $education, request());

        return response()->json([
            'message' => 'Riwayat pendidikan berhasil dihapus.',
        ]);
    }
}
