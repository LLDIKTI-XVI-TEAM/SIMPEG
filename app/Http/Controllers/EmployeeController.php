<?php

namespace App\Http\Controllers;

use App\Actions\Employees\CreateEmployeeAction;
use App\Actions\Employees\ListEmployeesAction;
use App\Actions\Employees\ShowEmployeeAction;
use App\Actions\Employees\ShowMyProfileAction;
use App\Actions\Employees\UpdateEmployeeAction;
use App\Http\Requests\ListEmployeesRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(ListEmployeesRequest $request, ListEmployeesAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Daftar pegawai berhasil diambil.',
            'employees' => $action->execute($request->validated()),
        ]);
    }

    public function store(StoreEmployeeRequest $request, CreateEmployeeAction $action): JsonResponse|RedirectResponse
    {
        $employee = $action->execute($request->validated(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Data pegawai berhasil ditambahkan.',
                'employee' => $employee,
            ], 201);
        }

        return back()->with('success', 'Data pegawai berhasil ditambahkan.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, UpdateEmployeeAction $action): JsonResponse|RedirectResponse
    {
        $employee = $action->execute($employee, $request->validated(), $request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Data pegawai berhasil diperbarui.',
                'employee' => $employee,
            ]);
        }

        return back()->with('success', 'Data pegawai berhasil diperbarui.');
    }

    public function show(Employee $employee, ShowEmployeeAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Detail pegawai berhasil diambil.',
            'employee' => $action->execute($employee),
        ]);
    }

    public function myProfile(Request $request, ShowMyProfileAction $action): JsonResponse
    {
        return response()->json([
            'message' => 'Detail profil pegawai berhasil diambil.',
            'employee' => $action->execute($request->user()?->employee),
        ]);
    }
}
