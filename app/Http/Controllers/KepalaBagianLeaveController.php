<?php

namespace App\Http\Controllers;

use App\Actions\Cuti\ListKepalaBagianLeavesAction;
use App\Http\Requests\Cuti\KepalaBagianLeaveFilterRequest;
use App\Models\LeaveRequest;
use App\Models\RefJenisCuti;
use App\Services\Employees\KepalaBagianScopeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KepalaBagianLeaveController extends Controller
{
    public function index(KepalaBagianLeaveFilterRequest $request, ListKepalaBagianLeavesAction $action)
    {
        abort_if($request->user()?->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');

        return view('kabag.cuti.index', [
            'leaves' => $action->execute($request->user(), $request->validated()),
            'filters' => $request->validated(),
            'jenisCutiOptions' => RefJenisCuti::query()->orderBy('nama')->get(['id', 'nama']),
        ]);
    }

    public function show(Request $request, LeaveRequest $leave, KepalaBagianScopeService $scope)
    {
        $user = $request->user();
        abort_if($user?->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');
        abort_unless($scope->hasDirectReport($user, $leave->employee_id), 403);

        $leave->load([
            'employee:id,nama_lengkap,nip,jabatan_terakhir,golongan_terakhir',
            'jenisCuti:id,nama',
            'steps.approver:id,nama_lengkap',
            'approvals.approver:id,nama_lengkap',
        ]);
        $activeStep = $leave->steps->firstWhere('status', 'active');
        $attachmentAvailable = $leave->lampiran_path !== null
            && Storage::disk('public')->exists($leave->lampiran_path);

        return view('kabag.cuti.show', [
            'leave' => $leave,
            'activeStep' => $activeStep,
            'canDecide' => $activeStep !== null
                && $activeStep->approver_employee_id === $user->employee_id
                && in_array($leave->status, ['menunggu_approval', 'ditangguhkan'], true),
            'attachmentAvailable' => $attachmentAvailable,
        ]);
    }

    public function downloadAttachment(Request $request, LeaveRequest $leave, KepalaBagianScopeService $scope)
    {
        $user = $request->user();
        abort_if($user?->employee_id === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');
        abort_unless($scope->hasDirectReport($user, $leave->employee_id), 403);
        abort_if(
            $leave->lampiran_path === null || ! Storage::disk('public')->exists($leave->lampiran_path),
            404,
        );

        $extension = pathinfo($leave->lampiran_path, PATHINFO_EXTENSION) ?: 'file';

        return Storage::disk('public')->download(
            $leave->lampiran_path,
            'Lampiran_Cuti_'.strtoupper(substr($leave->id, 0, 8)).'.'.$extension,
        );
    }
}
