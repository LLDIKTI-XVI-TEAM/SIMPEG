<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreateRankHistoryAction;
use App\Actions\Histories\ListRankHistoriesAction;
use App\Actions\Histories\UploadRankHistorySkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\History\StoreRankHistoryRequest;
use App\Http\Requests\History\UploadHistorySkRequest;
use App\Models\Employee;
use App\Models\RankHistory;
use App\Support\Histories\EmployeeHistoryPayload;
use Illuminate\Http\JsonResponse;

class RankHistoryController extends Controller
{
    public function index(
        Employee $employee,
        ListRankHistoriesAction $action,
    ): JsonResponse {
        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $action->execute($employee),
        ]);
    }

    public function store(
        StoreRankHistoryRequest $request,
        Employee $employee,
        CreateRankHistoryAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $validated = $request->validated();
        $warning = null;
        $hasFile = array_key_exists('file_sk', $validated) && $validated['file_sk'] !== null && $validated['file_sk'] !== '';
        $canCreateDoc = $request->user()?->hasPermission('dokumen_sk.create') || $request->user()?->getEffectiveRole() === 'super_admin';
        if ($hasFile && ! $canCreateDoc) {
            unset($validated['file_sk']);
            $warning = 'Riwayat kepangkatan berhasil disimpan, tetapi berkas SK tidak diunggah karena Anda tidak memiliki permission dokumen_sk.create.';
        }

        $history = $action->execute($employee, $validated, $request);

        $response = [
            'message' => 'Riwayat kepangkatan berhasil ditambahkan.',
            'history' => $payload->rank($history, $employee),
        ];
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        return response()->json($response, 201);
    }

    public function uploadSk(
        UploadHistorySkRequest $request,
        Employee $employee,
        RankHistory $rank,
        UploadRankHistorySkAction $action,
        EmployeeHistoryPayload $payload,
    ): JsonResponse {
        $history = $action->execute($employee, $rank, $request->file('file_sk'), $request);

        return response()->json([
            'message' => 'Berkas SK kepangkatan berhasil diperbarui.',
            'history' => $payload->rank($history, $employee),
        ]);
    }
}
