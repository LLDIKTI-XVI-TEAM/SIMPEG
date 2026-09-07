<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\DeclineLeaveAction;
use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\RecordDutyPostponementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\KepalaBagianLeaveDecisionRequest;
use App\Http\Requests\Cuti\RecordDutyPostponementRequest;
use App\Models\LeaveRequest;
use App\Services\Employees\KepalaBagianScopeService;

class KepalaBagianLeaveDecisionController extends Controller
{
    public function store(
        KepalaBagianLeaveDecisionRequest $request,
        LeaveRequest $leave,
        KepalaBagianScopeService $scope,
        ApproveLeaveAction $approve,
        PostponeLeaveAction $postpone,
        DeclineLeaveAction $decline,
    ) {
        $user = $request->user();
        $actor = $user?->employee;
        abort_if($actor === null, 403, 'Akun Atasan Langsung belum tertaut ke data pegawai.');
        abort_unless($scope->hasDirectReport($user, $leave->employee_id), 403);

        $payload = $request->validated();
        match ($payload['keputusan']) {
            'DISETUJUI' => $approve->execute($leave, $actor, $payload['active_step_id'], $payload['revision_version'], $payload['catatan'] ?? null, $request),
            'DITANGGUHKAN' => $postpone->execute($leave, $actor, $payload['active_step_id'], $payload['revision_version'], $payload['catatan'], $request),
            'TIDAK_DISETUJUI' => $decline->execute($leave, $actor, $payload['active_step_id'], $payload['revision_version'], $payload['catatan'], $request),
        };

        $message = match ($payload['keputusan']) {
            'DISETUJUI' => 'Pengajuan cuti berhasil disetujui.',
            'DITANGGUHKAN' => 'Pengajuan cuti ditangguhkan dan pemohon telah diberi tahu.',
            'TIDAK_DISETUJUI' => 'Pengajuan cuti tidak disetujui dan pemohon telah diberi tahu.',
        };

        return redirect()->route('kepala-bagian.cuti.show', $leave)->with('success', $message);
    }

    /** Menjaga scope bawahan sebelum Action memverifikasi approver snapshot di bawah lock. */
    public function recordDutyPostponement(
        RecordDutyPostponementRequest $request,
        LeaveRequest $leave,
        KepalaBagianScopeService $scope,
        RecordDutyPostponementAction $action,
    ) {
        $user = $request->user();
        $actor = $user?->employee;
        abort_if($user === null || $actor === null, 403, 'Akun Atasan Langsung belum tertaut ke data pegawai.');
        abort_unless($scope->hasDirectReport($user, $leave->employee_id), 403);

        $payload = $request->validated();
        $action->execute($leave, $actor, $user, $payload['active_step_id'], $payload['revision_version'], $payload['alasan']);

        return redirect()->route('kepala-bagian.cuti.show', $leave)
            ->with('success', 'Cuti Tahunan ditangguhkan karena tugas dinas dan hak terkait telah dilindungi untuk satu tahun berikutnya.');
    }
}
