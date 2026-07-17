<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\LookupEmployeesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\EmployeeLookupRequest;
use Illuminate\Http\JsonResponse;

class CutiEmployeeLookupController extends Controller
{
    /**
     * Menyediakan hasil autocomplete pegawai aktif dengan payload yang dibatasi.
     */
    public function __invoke(EmployeeLookupRequest $request, LookupEmployeesAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute((string) $request->validated('q')),
        ]);
    }
}
