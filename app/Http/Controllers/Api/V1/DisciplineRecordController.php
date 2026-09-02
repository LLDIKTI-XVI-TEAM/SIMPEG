<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreateDisciplineRecordAction;
use App\Actions\Histories\ListDisciplineRecordsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StoreDisciplineRecordRequest;
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
        $validated = $request->validated();
        $warning = null;
        $hasFile = (array_key_exists('file_sk', $validated) && $validated['file_sk'] !== null && $validated['file_sk'] !== '') || ! empty($validated['dokumen_id']);
        $canCreateDoc = $request->user()?->hasPermission('dokumen_sk.create') || $request->user()?->getEffectiveRole() === 'super_admin';
        if ($hasFile && ! $canCreateDoc) {
            unset($validated['file_sk'], $validated['dokumen_id']);
            $warning = 'Riwayat disiplin berhasil disimpan, tetapi berkas SK tidak diunggah karena Anda tidak memiliki permission dokumen_sk.create.';
        }

        $record = $action->execute($employee, $validated, $request);

        $response = [
            'message' => 'Riwayat disiplin berhasil ditambahkan.',
            'record' => $record,
        ];
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        return response()->json($response, 201);
    }
}
