<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Employee;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class EmployeeController extends Controller
{
    public function store(StoreEmployeeRequest $request): JsonResponse|RedirectResponse
    {
        $employee = Employee::create($request->validated());

        AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->toArray(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Data pegawai berhasil ditambahkan.',
                'employee' => $employee,
            ], 201);
        }

        return back()->with('success', 'Data pegawai berhasil ditambahkan.');
    }
}
