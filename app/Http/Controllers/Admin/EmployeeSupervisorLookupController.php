<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\LookupSupervisorCandidatesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\SupervisorLookupRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

class EmployeeSupervisorLookupController extends Controller
{
    /**
     * Menyediakan hasil autocomplete Kepala Bagian yang dibatasi untuk satu pegawai target.
     */
    public function __invoke(
        SupervisorLookupRequest $request,
        string $id,
        LookupSupervisorCandidatesAction $action,
    ): JsonResponse {
        $employee = Employee::findOrFail($id);

        return response()->json([
            'data' => $action->execute((string) $request->validated('q'), $employee->id),
        ]);
    }
}
