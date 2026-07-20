<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\EmployeeFamilies\ListEmployeeFamiliesAction;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint self-service data keluarga khusus role pegawai.
 *
 * Employee selalu di-resolve dari user yang sedang login agar data pegawai lain tidak terbaca.
 */
class MyFamilyController extends Controller
{
    public function index(ListEmployeeFamiliesAction $action): JsonResponse
    {
        $employee = $this->resolveEmployee();

        return response()->json([
            'employee_id' => $employee->id,
            'families' => $action->execute($employee),
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
