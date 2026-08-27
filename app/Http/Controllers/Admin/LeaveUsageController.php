<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\CorrectAnnualLeaveUsageAction;
use App\Actions\Cuti\DownloadLeaveUsageReconciliationDocumentAction;
use App\Actions\Cuti\ReconcileAnnualLeaveUsageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\CorrectAnnualLeaveUsageRequest;
use App\Http\Requests\Cuti\ReconcileAnnualLeaveUsageRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LeaveUsageController extends Controller
{
    /** Menyerahkan snapshot rekonsiliasi tervalidasi ke Action tanpa menaruh aturan saldo di controller. */
    public function reconcile(
        ReconcileAnnualLeaveUsageRequest $request,
        string $employee,
        ReconcileAnnualLeaveUsageAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->execute($employee, $request->validated(), $actor, $request);

        return back()->with('success', 'Data pemakaian cuti tahunan berhasil disimpan.');
    }

    /** Mengganti set aktif secara utuh beserta satu bukti privat melalui Action transaksional. */
    public function correct(
        CorrectAnnualLeaveUsageRequest $request,
        string $reconciliation,
        CorrectAnnualLeaveUsageAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->execute(
            $reconciliation,
            $request->validated(),
            $request->file('dokumen'),
            $actor,
            $request,
        );

        return back()->with('success', 'Data pemakaian cuti tahunan berhasil diperbaiki.');
    }

    /** Meneruskan unduhan bukti koreksi ke Action yang mengunci role, permission, target, dan path. */
    public function downloadDocument(
        Request $request,
        string $reconciliation,
        string $document,
        DownloadLeaveUsageReconciliationDocumentAction $action,
    ): StreamedResponse {
        /** @var User $actor */
        $actor = $request->user();

        return $action->execute($reconciliation, $document, $actor);
    }
}
