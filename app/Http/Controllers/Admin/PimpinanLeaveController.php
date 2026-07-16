<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ListPimpinanLeavesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\PimpinanLeaveFilterRequest;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PimpinanLeaveController extends Controller
{
    public function index(PimpinanLeaveFilterRequest $request, ListPimpinanLeavesAction $leaves)
    {
        $filters = $request->validated();

        return view('pimpinan.cuti.index', [
            'leaves' => $leaves->execute($filters),
            'filters' => $filters,
            'jenisCutiOptions' => RefJenisCuti::query()->orderBy('nama')->get(['id', 'nama']),
            'unitKerjaOptions' => RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']),
        ]);
    }

    public function show(Request $request, LeaveRequest $leave)
    {
        $leave->load([
            'employee.leaveBalances',
            'jenisCuti',
            'steps.approver',
            'approvals.approver',
            'proof',
        ]);
        $activeStep = $leave->steps->firstWhere('status', 'active');
        $canDecide = $activeStep !== null
            && $activeStep->approver_employee_id === $request->user()?->employee_id
            && in_array($leave->status, ['menunggu_approval', 'ditangguhkan'], true);
        $attachmentAvailable = $leave->lampiran_path !== null
            && Storage::disk('public')->exists($leave->lampiran_path);

        return view('pimpinan.cuti.show', [
            'leave' => $leave,
            'activeStep' => $activeStep,
            'canDecide' => $canDecide,
            'balances' => $leave->employee?->leaveBalances->keyBy('tahun') ?? collect(),
            'attachmentAvailable' => $attachmentAvailable,
        ]);
    }
}
