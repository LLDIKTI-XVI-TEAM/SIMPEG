<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\RecordAdministrativeLeavePostponementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\RecordAdministrativeLeavePostponementRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class AdministrativeLeavePostponementController extends Controller
{
    /** Adapter hanya meneruskan alasan tervalidasi dan identitas server ke Action. */
    public function store(
        RecordAdministrativeLeavePostponementRequest $request,
        LeaveRequest $leaveRequest,
        RecordAdministrativeLeavePostponementAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $action->execute($leaveRequest, $actor, (string) $request->validated('alasan'), $request);

        return redirect()->route('cuti.show', $leaveRequest)
            ->with('success', 'Cuti ditangguhkan secara administratif. Seluruh pemakaian cuti pada pengajuan ini telah dibatalkan.');
    }
}
