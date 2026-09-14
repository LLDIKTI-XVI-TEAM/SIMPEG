<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\DownloadLeaveAttachmentAction;
use App\Actions\Cuti\DownloadStoredLeaveProofAction;
use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PimpinanLeaveDocumentController extends Controller
{
    public function show(Request $request, LeaveRequest $leave, DownloadStoredLeaveProofAction $action): StreamedResponse
    {
        return $action->forActor($leave, $request->user(), true);
    }

    public function download(Request $request, LeaveRequest $leave, DownloadStoredLeaveProofAction $action): StreamedResponse
    {
        return $action->forActor($leave, $request->user(), false);
    }

    public function downloadAttachment(LeaveRequest $leave, Request $request, DownloadLeaveAttachmentAction $action): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $action->forPimpinan($leave, $user);
    }
}
