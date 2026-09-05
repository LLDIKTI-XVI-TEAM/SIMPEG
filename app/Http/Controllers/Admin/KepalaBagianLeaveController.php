<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\DownloadLeaveAttachmentAction;
use App\Actions\Cuti\ShowKepalaBagianLeaveDetailAction;
use App\Actions\Cuti\ShowKepalaBagianLeaveIndexAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\KepalaBagianLeaveFilterRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\Request;

class KepalaBagianLeaveController extends Controller
{
    public function index(KepalaBagianLeaveFilterRequest $request, ShowKepalaBagianLeaveIndexAction $action)
    {
        abort_if($request->user()?->employee_id === null, 403, 'Akun Atasan Langsung belum tertaut ke data pegawai.');

        /** @var User $actor */
        $actor = $request->user();

        return view('kabag.cuti.index', $action->execute($actor, $request->validated()));
    }

    public function show(
        Request $request,
        LeaveRequest $leave,
        ShowKepalaBagianLeaveDetailAction $action,
    ) {
        /** @var User|null $actor */
        $actor = $request->user();

        return view('kabag.cuti.show', $action->execute($actor, $leave));
    }

    public function downloadAttachment(Request $request, LeaveRequest $leave, DownloadLeaveAttachmentAction $action)
    {
        /** @var User $user */
        $user = $request->user();

        return $action->forKepalaBagian($leave, $user);
    }
}
