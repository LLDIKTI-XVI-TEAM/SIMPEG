<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\CancelManualLeaveUsageAction;
use App\Actions\Cuti\CorrectManualLeaveUsageAction;
use App\Actions\Cuti\DownloadLeaveUsageDocumentAction;
use App\Actions\Cuti\LookupManualExternalApproversAction;
use App\Actions\Cuti\StoreManualLeaveUsageAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\CancelManualLeaveUsageRequest;
use App\Http\Requests\Cuti\CorrectManualLeaveUsageRequest;
use App\Http\Requests\Cuti\ManualExternalApproverLookupRequest;
use App\Http\Requests\Cuti\StoreManualLeaveUsageRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ManualLeaveUsageController extends Controller
{
    /** Menyediakan autocomplete approver dengan payload minimum setelah gate backend selesai. */
    public function lookupApprovers(
        ManualExternalApproverLookupRequest $request,
        LookupManualExternalApproversAction $action,
    ): JsonResponse {
        return response()
            ->json(['data' => $action->execute((string) $request->validated('q'), $request->user())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(
        StoreManualLeaveUsageRequest $request,
        string $employee,
        StoreManualLeaveUsageAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->execute($employee, $request->validated(), $request->file('dokumen'), $actor, $request);

        return back()->with('success', 'Pemakaian cuti manual berhasil dicatat.');
    }

    public function correct(
        CorrectManualLeaveUsageRequest $request,
        string $usage,
        CorrectManualLeaveUsageAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->execute($usage, $request->validated(), $request->file('dokumen'), $actor, $request);

        return back()->with('success', 'Pemakaian cuti manual berhasil dikoreksi.');
    }

    public function cancel(
        CancelManualLeaveUsageRequest $request,
        string $usage,
        CancelManualLeaveUsageAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->execute(
            $usage,
            (string) $request->validated('correction_reason'),
            $actor,
            $request,
        );

        return back()->with('success', 'Pemakaian cuti manual berhasil dibatalkan.');
    }

    public function download(
        string $usage,
        string $document,
        Request $request,
        DownloadLeaveUsageDocumentAction $action,
    ): StreamedResponse {
        /** @var User $actor */
        $actor = $request->user();

        return $action->execute($usage, $document, $actor);
    }
}
