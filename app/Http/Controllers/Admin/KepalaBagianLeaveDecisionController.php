<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\RejectLeaveAction;
use App\Actions\Cuti\RequestChangesLeaveAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\KepalaBagianLeaveDecisionRequest;
use App\Models\LeaveRequest;
use App\Services\Employees\KepalaBagianScopeService;

class KepalaBagianLeaveDecisionController extends Controller
{
    public function store(
        KepalaBagianLeaveDecisionRequest $request,
        LeaveRequest $leave,
        KepalaBagianScopeService $scope,
        ApproveLeaveAction $approve,
        RequestChangesLeaveAction $requestChanges,
        PostponeLeaveAction $postpone,
        RejectLeaveAction $reject,
    ) {
        $user = $request->user();
        $actor = $user?->employee;
        abort_if($actor === null, 403, 'Akun Kepala Bagian belum tertaut ke data pegawai.');
        abort_unless($scope->hasDirectReport($user, $leave->employee_id), 403);

        $payload = $request->validated();
        match ($payload['keputusan']) {
            'DISETUJUI' => $approve->execute($leave, $actor, $payload['catatan'] ?? null, $request),
            'PERUBAHAN' => $requestChanges->execute($leave, $actor, $payload['catatan'], $request),
            'DITANGGUHKAN' => $postpone->execute($leave, $actor, $payload['catatan'], $request),
            'TIDAK_DISETUJUI' => $reject->execute($leave, $actor, $payload['catatan'], $request),
        };

        $message = match ($payload['keputusan']) {
            'DISETUJUI' => 'Pengajuan cuti berhasil disetujui.',
            'PERUBAHAN' => 'Pengajuan cuti dikembalikan untuk perbaikan.',
            'DITANGGUHKAN' => 'Pengajuan cuti ditangguhkan dan pemohon telah diberi tahu.',
            'TIDAK_DISETUJUI' => 'Pengajuan cuti tidak disetujui dan pemohon telah diberi tahu.',
        };

        return redirect()->route('kepala-bagian.cuti.show', $leave)->with('success', $message);
    }
}
