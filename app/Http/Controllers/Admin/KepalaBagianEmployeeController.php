<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ListKepalaBagianEmployeesAction;
use App\Actions\Employees\ShowKepalaBagianEmployeeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\KepalaBagianEmployeeFilterRequest;
use App\Models\Employee;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use App\Models\PositionHistory;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Http\Request;

class KepalaBagianEmployeeController extends Controller
{
    public function index(KepalaBagianEmployeeFilterRequest $request, ListKepalaBagianEmployeesAction $action, KepalaBagianScopeService $scope)
    {
        abort_if($request->user()?->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');

        $reportIds = $scope->directReportIds($request->user());
        $unitKerjaIds = PositionHistory::query()
            ->where('is_latest', true)
            ->whereIn('employee_id', $reportIds)
            ->whereNotNull('unit_kerja_id')
            ->pluck('unit_kerja_id');
        $jenisPegawaiIds = Employee::query()
            ->whereIn('id', $reportIds)
            ->whereNotNull('jenis_pegawai_id')
            ->pluck('jenis_pegawai_id');

        return view('kabag.bawahan.index', [
            'employees' => $action->execute($request->user(), $request->validated()),
            'filters' => $request->validated(),
            'unitKerjas' => RefUnitKerja::whereIn('id', $unitKerjaIds)->orderBy('nama')->get(),
            'jenisPegawais' => RefJenisPegawai::whereIn('id', $jenisPegawaiIds)->orderBy('nama')->get(),
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
