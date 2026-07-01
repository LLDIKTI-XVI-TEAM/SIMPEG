<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\SaveApprovalChainConfigAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\ApprovalChainConfigRequest;
use App\Models\ApprovalConfig;
use App\Models\AuditLog;
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
        $eligibleUsers = User::whereIn('role', ['admin_kepegawaian', 'pimpinan', 'atasan_langsung'])
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

        return view('admin.cuti.konfigurasi', [
            'eligibleUsers' => $eligibleUsers,
            'stage2Id' => $stage2Id,
            'stage3Id' => $stage3Id,
            'stage2User' => $stage2Id !== null ? User::find($stage2Id) : null,
            'stage3User' => $stage3Id !== null ? User::find($stage3Id) : null,
            'auditRows' => $auditRows,
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
}
