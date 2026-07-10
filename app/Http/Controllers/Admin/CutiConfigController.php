<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApplyGlobalPybmcAction;
use App\Actions\Cuti\BackfillEmployeeApprovalChainsAction;
use App\Actions\Cuti\SaveApprovalChainConfigAction;
use App\Actions\Cuti\SaveEmployeeApprovalChainAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ApprovalChainConfigRequest;
use App\Http\Requests\Cuti\BackfillApprovalChainsRequest;
use App\Http\Requests\Cuti\EmployeeApprovalChainRequest;
use App\Http\Requests\Cuti\GlobalPybmcConfigRequest;
use App\Models\ApprovalConfig;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveApprovalChain;
use App\Models\LeavePybmcGlobalConfig;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Mengelola halaman konfigurasi rantai approval cuti (penentuan approver stage 2 dan stage 3).
 * Controller sengaja dibuat tipis: hanya merangkai data untuk tampilan dan mendelegasikan
 * penyimpanan ke SaveApprovalChainConfigAction agar logika bisnis tidak menumpuk di controller.
 */
class CutiConfigController extends Controller
{
    /**
     * Menampilkan halaman konfigurasi approval beserta nilai approver saat ini dan riwayat perubahannya.
     */
    public function index(): View
    {
        // Kandidat approver dibatasi pada role yang memang berwenang menyetujui cuti,
        // sehingga super_admin tidak salah memilih akun yang tidak relevan.
        $eligibleUsers = User::whereIn('role', ['admin_kepegawaian', 'pimpinan', 'kepala_bagian'])
            ->orderBy('name')
            ->get();

        $stage2Id = ApprovalConfig::getVal('stage2_approver_id');
        $stage3Id = ApprovalConfig::getVal('stage3_approver_id');

        // Riwayat perubahan diambil langsung dari audit log nyata (bukan mock session),
        // sehingga jejak konfigurasi konsisten dengan modul audit lain.
        $auditRows = AuditLog::where('auditable_type', 'ApprovalConfig')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $chainStats = [
            'active' => LeaveApprovalChain::where('is_active', true)->count(),
        ];
        $globalPybmc = LeavePybmcGlobalConfig::query()
            ->with('approver')
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();

        return view('admin.cuti.konfigurasi', [
            'eligibleUsers' => $eligibleUsers,
            'stage2Id' => $stage2Id,
            'stage3Id' => $stage3Id,
            'stage2User' => $stage2Id !== null ? User::find($stage2Id) : null,
            'stage3User' => $stage3Id !== null ? User::find($stage3Id) : null,
            'auditRows' => $auditRows,
            'chainStats' => $chainStats,
            'globalPybmc' => $globalPybmc,
        ]);
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
        $result = $action->execute($request->user(), (string) $request->validated('backfill_reason'));

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
     * Menyimpan chain khusus pegawai; PYBMC final diisi dari konfigurasi global bila tidak dikirim form.
     */
    public function storeEmployeeChain(EmployeeApprovalChainRequest $request, Employee $employee, SaveEmployeeApprovalChainAction $action): RedirectResponse
    {
        $steps = collect($request->validated('steps'))
            ->map(fn (array $step): array => [
                'step_type' => (string) $step['step_type'],
                'role_label' => (string) $step['role_label'],
                'approver_employee_id' => (string) $step['approver_employee_id'],
                'is_final' => false,
            ])
            ->values()
            ->all();

        $action->execute($employee, $steps, $request->user(), (string) $request->validated('reason'));

        return redirect()
            ->route('cuti.config')
            ->with('success', 'Chain approval pegawai berhasil disimpan.');
    }
}
