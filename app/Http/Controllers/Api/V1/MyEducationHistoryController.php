<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\CreateEducationHistoryAction;
use App\Actions\Histories\DeleteEducationHistoryAction;
use App\Actions\Histories\ListEducationHistoriesAction;
use App\Actions\Histories\UpdateEducationHistoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreMyEducationHistoryRequest;
use App\Http\Requests\Employee\UpdateMyEducationHistoryRequest;
use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\User;
use App\Support\Histories\EducationHistoryPayload;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint self-service riwayat pendidikan khusus role pegawai.
 *
 * Employee selalu di-resolve dari user yang sedang login (auth()->user()->employee_id),
 * sehingga pegawai tidak dapat mengakses atau mengubah riwayat pendidikan pegawai lain.
 * Otorisasi berlapis: role middleware → FormRequest::authorize() → controller guard → action guard.
 */
class MyEducationHistoryController extends Controller
{
    public function __construct(private readonly EducationHistoryPayload $payload) {}

    public function index(ListEducationHistoriesAction $action): JsonResponse
    {
        $employee = $this->resolveEmployee();

        return response()->json([
            'employee_id' => $employee->id,
            'histories'   => $action->execute($employee),
        ]);
    }

    public function store(
        StoreMyEducationHistoryRequest $request,
        CreateEducationHistoryAction $action,
    ): JsonResponse {
        $employee = $this->resolveEmployee();

        $history = $action->execute($employee, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat pendidikan berhasil ditambahkan.',
            'history' => $this->payload->response($history),
        ], 201);
    }

    public function update(
        UpdateMyEducationHistoryRequest $request,
        EducationHistory $education,
        UpdateEducationHistoryAction $action,
    ): JsonResponse {
        $employee = $this->resolveEmployee();

        // Lapisan otorisasi eksplisit: pastikan record yang diedit benar-benar milik pegawai login.
        abort_unless(
            $education->employee_id === $employee->id,
            403,
            'Anda hanya dapat mengubah riwayat pendidikan milik Anda sendiri.',
        );

        $history = $action->execute($employee, $education, $request->validated(), $request);

        return response()->json([
            'message' => 'Riwayat pendidikan berhasil diperbarui.',
            'history' => $this->payload->response($history),
        ]);
    }

    public function destroy(
        EducationHistory $education,
        DeleteEducationHistoryAction $action,
    ): JsonResponse {
        $employee = $this->resolveEmployee();

        // Lapisan otorisasi eksplisit: pastikan record yang dihapus benar-benar milik pegawai login.
        abort_unless(
            $education->employee_id === $employee->id,
            403,
            'Anda hanya dapat menghapus riwayat pendidikan milik Anda sendiri.',
        );

        $action->execute($employee, $education, request());

        return response()->json([
            'message' => 'Riwayat pendidikan berhasil dihapus.',
        ]);
    }

    /**
     * Mengambil model Employee dari user yang sedang login.
     * Guard role middleware sudah memastikan user login, memiliki role pegawai,
     * dan FormRequest::authorize() sudah memastikan employee_id tidak null.
     */
    private function resolveEmployee(): Employee
    {
        /** @var User $user */
        $user = auth()->user();

        return Employee::findOrFail($user->employee_id);
    }
}
