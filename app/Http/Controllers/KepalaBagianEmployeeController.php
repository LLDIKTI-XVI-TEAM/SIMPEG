<?php

namespace App\Http\Controllers;

use App\Actions\Employees\ListKepalaBagianEmployeesAction;
use App\Actions\Employees\ShowKepalaBagianEmployeeAction;
use App\Http\Requests\Employee\KepalaBagianEmployeeFilterRequest;
use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Http\Request;

class KepalaBagianEmployeeController extends Controller
{
    public function index(KepalaBagianEmployeeFilterRequest $request, ListKepalaBagianEmployeesAction $action)
    {
        abort_if($request->user()?->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');

        return view('kabag.bawahan.index', [
            'employees' => $action->execute($request->user(), $request->validated()),
            'filters' => $request->validated(),
            'unitKerjas' => RefUnitKerja::orderBy('nama')->get(),
            'jenisPegawais' => RefJenisPegawai::orderBy('nama')->get(),
        ]);
    }

    public function show(Request $request, Employee $employee, ShowKepalaBagianEmployeeAction $action)
    {
        abort_if($request->user()?->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');

        return view('kabag.bawahan.show', [
            'employee' => $action->execute($request->user(), $employee),
        ]);
    }
}
