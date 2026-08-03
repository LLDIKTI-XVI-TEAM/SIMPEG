<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\ChangeEmployeeStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\ChangeEmployeeStatusRequest;
use App\Models\Employee;
use App\Models\RefStatusPegawai;

class StatusPegawaiController extends Controller
{
    public function index()
    {
        $employees = Employee::orderBy('nama_lengkap', 'asc')->get();
        $statusOptions = RefStatusPegawai::where('is_active', true)->orderByDesc('is_default')->orderBy('nama')->get();

        return view('admin.status-pegawai.index', compact('employees', 'statusOptions'));
    }

    public function store(ChangeEmployeeStatusRequest $request, ChangeEmployeeStatusAction $action)
    {
        $validated = $request->validated();
        $employee = Employee::findOrFail($validated['pegawai_id']);

        try {
            $action->execute($employee, $validated, $request, $request->file('berkas'));

            return redirect()->back()->with('success', 'Status pegawai '.$employee->nama_lengkap.' berhasil diperbarui.');
        } catch (\Throwable $e) {
            return back()
                ->withInput($request->except('berkas'))
                ->with('error', 'Gagal memperbarui status pegawai: '.$e->getMessage());
        }
    }
}
