<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApplyChainTemplateToUnitAction;
use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\BackfillEmployeeApprovalChainsAction;
use App\Actions\Cuti\SaveApprovalChainConfigAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Actions\Cuti\ShowCutiConfigPageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ApplyChainTemplateToUnitRequest;
use App\Http\Requests\Cuti\ApprovalChainConfigRequest;
use App\Http\Requests\Cuti\BackfillApprovalChainsRequest;
use App\Http\Requests\Cuti\CutiConfigPageRequest;
use App\Http\Requests\Cuti\EmployeeApprovalChainRequest;
use App\Http\Requests\Cuti\GlobalPybmcConfigRequest;
use App\Models\Employee;
use App\Models\RefUnitKerja;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Mengelola halaman konfigurasi rantai approval cuti per pegawai.
 * Controller hanya meneruskan request ke Action agar query data tampilan tidak menumpuk di adapter HTTP.
 */
class CutiConfigController extends Controller
{
    /**
     * Menampilkan pembuat chain per pegawai beserta alat migrasi dan riwayat konfigurasi lama.
     */
    public function index(CutiConfigPageRequest $request, ShowCutiConfigPageAction $action): View
    {
        return view('admin.cuti.konfigurasi', $action->execute(
            $request->validated('search'),
            $request->validated('employee_id'),
            $request->validated('approver_search'),
            $request->old('steps'),
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
     * Membuat chain dinamis awal dari konfigurasi lama supaya pegawai aktif tidak terkunci saat runtime baru aktif.
     */
    public function backfill(BackfillApprovalChainsRequest $request, BackfillEmployeeApprovalChainsAction $action): RedirectResponse
    {
        $result = $action->execute($request->user(), (string) $request->validated('backfill_reason'), $request);

        return redirect()
            ->route('cuti.config')
            ->with('success', sprintf(
                'Backfill chain approval selesai: %d dibuat, %d dilewati, %d tanpa Kepala Bagian.',
                count($result['created_employee_ids']),
                count($result['skipped_employee_ids']),
                count($result['missing_kepala_bagian_employee_ids']),
            ));
    }

    /**
     * Menyimpan PYBMC global sebagai final approver default untuk chain baru.
     */
    public function updateGlobalPybmc(GlobalPybmcConfigRequest $request, ApplyGlobalPybmcAction $action): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('approver_employee_id'));

        $action->execute($employee, $request->user(), (string) $request->validated('pybmc_reason'));

        return redirect()
            ->route('cuti.config')
            ->with('success', 'PYBMC global berhasil diperbarui.');
    }

    /**
     * Menerapkan chain milik satu pegawai ke seluruh anggota unit kerja yang sama.
     * Hasil per kategori dikembalikan ke halaman agar pegawai yang terlewat tidak hilang senyap.
     */
    public function applyTemplateToUnit(ApplyChainTemplateToUnitRequest $request, ApplyChainTemplateToUnitAction $action): RedirectResponse
    {
        $unitKerja = RefUnitKerja::findOrFail($request->validated('unit_kerja_id'));
        $sumber = Employee::findOrFail($request->validated('source_employee_id'));

        $result = $action->execute(
            $unitKerja,
            $sumber,
            $request->user(),
            (string) $request->validated('template_reason'),
            $request,
        );

        return redirect()
            ->route('cuti.config')
            ->with('success', sprintf(
                'Template chain diterapkan ke unit %s: %d dibuat, %d ditimpa, %d dilewati karena nonaktif, %d dilewati karena tanpa Kepala Bagian efektif, %d dilewati karena menjadi approver wajib pada template, %d pegawai aktif tidak terjangkau karena tanpa riwayat jabatan terkini.',
                $unitKerja->nama,
                count($result['applied_employee_ids']),
                count($result['overwritten_employee_ids']),
                count($result['skipped_inactive_employee_ids']),
                count($result['skipped_missing_kepala_bagian_employee_ids']),
                count($result['skipped_self_approval_employee_ids']),
                $result['unreachable_without_latest_position_count'],
            ));
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

        $action->execute($employee, $steps, $request->user(), (string) $request->validated('reason'), $request);

        return redirect()
            ->route('cuti.config')
            ->with('success', 'Chain approval pegawai berhasil disimpan.');
    }
}
