<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\SaveApprovalChainConfigAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Actions\Cuti\ShowCutiConfigPageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ApprovalChainConfigRequest;
use App\Http\Requests\Cuti\CutiConfigPageRequest;
use App\Http\Requests\Cuti\EmployeeApprovalChainRequest;
use App\Http\Requests\Cuti\GlobalPybmcConfigRequest;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Mengelola halaman konfigurasi rantai approval cuti per pegawai.
 * Controller hanya meneruskan request ke Action agar query data tampilan tidak menumpuk di adapter HTTP.
 */
class CutiConfigController extends Controller
{
    /**
     * Menampilkan editor individual, composer massal, dan riwayat konfigurasi.
     */
    public function index(CutiConfigPageRequest $request, ShowCutiConfigPageAction $action): View
    {
        return view('admin.cuti.konfigurasi', $action->execute(
            $request->user(),
            $request->validated('search'),
            $request->validated('employee_id'),
            $request->validated('approver_search'),
            $request->old('steps'),
            $request->validated('tab'),
            $request->validated('step'),
            $request->old('approver_employee_id'),
            $request->old('kepala_bagian_id'),
        ));
    }

    /**
     * Menyimpan perubahan konfigurasi approver. Validasi dan otorisasi ditangani FormRequest,
     * penyimpanan beserta auditnya didelegasikan ke Action.
     */
    public function update(ApprovalChainConfigRequest $request, SaveApprovalChainConfigAction $action): RedirectResponse
    {
        $action->execute($request->validated(), $request);

        return redirect()
            ->route('cuti.config')
            ->with('success', 'Konfigurasi Approval Cuti berhasil diperbarui.');
    }

    /**
     * Menyimpan PYBMC global sebagai final approver default untuk chain baru.
     */
    public function updateGlobalPybmc(GlobalPybmcConfigRequest $request, ApplyGlobalPybmcAction $action): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('approver_employee_id'));

        try {
            $action->execute($employee, $request->user(), (string) $request->validated('pybmc_reason'));
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return redirect()->route('cuti.config', ['tab' => 'pybmc'])
                ->withInput()
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('cuti.config', ['tab' => 'pybmc'])
            ->with('success', 'PYBMC global berhasil diperbarui.');
    }

    /**
     * Menyimpan chain khusus pegawai; PYBMC final diisi dari konfigurasi global bila tidak dikirim form.
     */
    public function storeEmployeeChain(EmployeeApprovalChainRequest $request, Employee $employee, SaveEmployeeApprovalChainAction $action): RedirectResponse
    {
        $steps = collect($request->validated('steps'))
            ->map(fn (array $step): array => [
                'step_type' => (string) $step['step_type'],
                'role_label' => (string) $step['role_label'],
                'approver_employee_id' => (string) $step['approver_employee_id'],
                'is_final' => $step['step_type'] === 'pybmc',
            ])
            ->values()
            ->all();

        try {
            $action->execute($employee, $steps, $request->user(), $request->validated('reason'), $request);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            return redirect()->route('cuti.config', [
                'tab' => 'pegawai',
                'employee_id' => $employee->id,
            ])->withInput()->withErrors($exception->errors());
        }

        return redirect()
            ->route('cuti.config', [
                'tab' => 'pegawai',
                'employee_id' => $employee->id,
            ])
            ->with('success', 'Chain approval pegawai berhasil disimpan.');
    }
}
