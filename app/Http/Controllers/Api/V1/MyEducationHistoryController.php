<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Histories\ListEducationHistoriesAction;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint self-service riwayat pendidikan khusus role pegawai.
 *
 * Employee selalu di-resolve dari user yang sedang login agar riwayat pegawai lain tidak terbaca.
 */
class MyEducationHistoryController extends Controller
{
    public function index(ListEducationHistoriesAction $action): JsonResponse
    {
        $employee = $this->resolveEmployee();

        return response()->json([
            'employee_id' => $employee->id,
            'histories' => $action->execute($employee),
        ]);
    }

    /**
     * Mengambil model Employee dari user yang sedang login.
     */
    private function resolveEmployee(): Employee
    {
        /** @var User $user */
        $user = request()->user();

        return Employee::findOrFail($user->employee_id);
    }
}
