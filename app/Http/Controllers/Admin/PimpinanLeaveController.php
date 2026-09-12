<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ListPimpinanLeavesAction;
use App\Actions\Cuti\RedirectToLeaveDetailAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\PimpinanLeaveFilterRequest;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;

class PimpinanLeaveController extends Controller
{
    public function index(PimpinanLeaveFilterRequest $request, ListPimpinanLeavesAction $leaves)
    {
        return view('pimpinan.cuti.index', $leaves->execute($request->user(), $request->validated()));
    }

    public function show(Request $request, LeaveRequest $leave, RedirectToLeaveDetailAction $detail)
    {
        $url = $detail->execute($leave, $request->user(), 'pimpinan');
        // Redirect tambahan tidak boleh menghabiskan error dan draft dari POST sebelumnya.
        $request->session()->reflash();

        return redirect($url);
    }
}
