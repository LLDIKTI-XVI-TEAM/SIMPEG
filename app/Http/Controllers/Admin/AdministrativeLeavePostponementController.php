<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\RecordAdministrativeLeavePostponementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\RecordAdministrativeLeavePostponementRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Cuti\LeaveDetailNavigation;
use Illuminate\Http\RedirectResponse;

final class AdministrativeLeavePostponementController extends Controller
{
    public function __construct(private readonly LeaveDetailNavigation $navigation) {}

    /** Adapter hanya meneruskan alasan tervalidasi dan identitas server ke Action. */
    public function store(
        RecordAdministrativeLeavePostponementRequest $request,
        LeaveRequest $leaveRequest,
        RecordAdministrativeLeavePostponementAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->execute($leaveRequest, $actor, (string) $request->validated('alasan'), $request);

        return redirect()->route('cuti.show', $this->navigation->detailParameters(
            $actor, $leaveRequest->id, $request->input('from'), $request->input('return', []),
        ))
            ->with('success', 'Cuti ditangguhkan secara administratif. Seluruh pemakaian cuti pada pengajuan ini telah dibatalkan.');
    }
}
