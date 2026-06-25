<?php

namespace App\Http\Controllers;

use App\Actions\Histories\CreateDisciplineRecordAction;
use App\Actions\Histories\ListDisciplineRecordsAction;
use App\Http\Requests\StoreDisciplineRecordRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class DisciplineRecordController extends Controller
{
    public function index(Employee $employee, ListDisciplineRecordsAction $action): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'records' => $action->execute($employee),
        ]);
    }

    public function store(
        StoreDisciplineRecordRequest $request,
        Employee $employee,
        CreateDisciplineRecordAction $action,
    ): JsonResponse {
        $record = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat disiplin berhasil ditambahkan.',
            'record' => $record,
        ], 201);
    }
}
