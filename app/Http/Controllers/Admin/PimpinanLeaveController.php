<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ListPimpinanLeavesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\PimpinanLeaveFilterRequest;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Models\RefUnitKerja;
use App\Services\EmployeeFileStorageService;
use Illuminate\Http\Request;

class PimpinanLeaveController extends Controller
{
    public function index(PimpinanLeaveFilterRequest $request, ListPimpinanLeavesAction $leaves)
    {
        $filters = $request->validated();
        $data = $leaves->execute($request->user(), $filters);
        // Jangkar ke awal bulan agar opsi periode tetap berurutan pada tanggal 29-31.
        $bulanBerjalan = now()->startOfMonth();

        return view('pimpinan.cuti.index', array_merge($data, [
            'filters' => $filters,
            'optPeriodes' => collect(range(0, 11))
                ->map(fn (int $offset): string => $bulanBerjalan->copy()->subMonths($offset)->format('Y-m')),
            'jenisCutiOptions' => RefJenisCuti::query()->orderBy('nama')->get(['id', 'nama']),
            'unitKerjaOptions' => RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']),
        ]));
    }

    public function show(Request $request, LeaveRequest $leave, EmployeeFileStorageService $files)
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
        $attachmentAvailable = $files->hasLeaveAttachment($leave->lampiran_path, $leave->employee_id);

        return view('pimpinan.cuti.show', [
            'leave' => $leave,
            'activeStep' => $activeStep,
            'canDecide' => $canDecide,
            'balances' => $leave->employee?->leaveBalances->keyBy('tahun') ?? collect(),
            'attachmentAvailable' => $attachmentAvailable,
        ]);
    }
}
