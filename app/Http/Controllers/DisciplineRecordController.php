<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDisciplineRecordRequest;
use App\Models\Employee;
use App\Models\DisciplineRecord;
use App\Services\EmployeeHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

class DisciplineRecordController extends Controller
{
    public function index(Employee $employee): JsonResponse
    {
        return response()->json([
            'employee_id' => $employee->id,
            'records' => $employee->disciplineRecords()
                ->orderByDesc('tanggal_mulai')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (DisciplineRecord $record): array => $this->recordPayload($record))
                ->values(),
        ]);
    }

    public function store(
        StoreDisciplineRecordRequest $request,
        Employee $employee,
        EmployeeHistoryService $service,
    ): JsonResponse {
        $record = $service->createDisciplineRecord($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat disiplin berhasil ditambahkan.',
            'record' => $this->recordPayload($record),
        ], 201);
    }

    /**
     * Membuka hanya field riwayat disiplin yang dibutuhkan layar/detail khusus disiplin.
     */
    private function recordPayload(DisciplineRecord $record): array
    {
        return Arr::only($record->toArray(), [
            'id',
            'employee_id',
            'jenis_hukuman',
            'deskripsi',
            'tanggal_mulai',
            'tanggal_berakhir',
            'no_sk',
            'tanggal_sk',
            'file_sk',
            'is_active',
            'created_at',
        ]);
    }
}
