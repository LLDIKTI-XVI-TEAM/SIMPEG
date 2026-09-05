<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\DeclineLeaveAction;
use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\RecordDutyPostponementAction;
use App\Actions\Cuti\RequestChangesLeaveAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\PimpinanLeaveDecisionRequest;
use App\Http\Requests\Cuti\RecordDutyPostponementRequest;
use App\Models\LeaveRequest;

class PimpinanLeaveDecisionController extends Controller
{
    public function store(
        PimpinanLeaveDecisionRequest $request,
        LeaveRequest $leave,
        ApproveLeaveAction $approve,
        RequestChangesLeaveAction $requestChanges,
        PostponeLeaveAction $postpone,
        DeclineLeaveAction $decline,
    ) {
        $actor = $request->user()?->employee;
        abort_if($actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat memutuskan cuti.');

        $payload = $request->validated();
        match ($payload['keputusan']) {
            'DISETUJUI' => $approve->execute($leave, $actor, $payload['active_step_id'], $payload['catatan'] ?? null, $request),
            'PERUBAHAN' => $requestChanges->execute($leave, $actor, $payload['active_step_id'], $payload['catatan'], $request),
            'DITANGGUHKAN' => $postpone->execute($leave, $actor, $payload['active_step_id'], $payload['catatan'], $request),
            'TIDAK_DISETUJUI' => $decline->execute($leave, $actor, $payload['active_step_id'], $payload['catatan'], $request),
        };
        $message = match ($payload['keputusan']) {
            'DISETUJUI' => 'Pengajuan cuti berhasil disetujui.',
            'PERUBAHAN' => 'Pengajuan cuti dikembalikan untuk perbaikan.',
            'DITANGGUHKAN' => 'Pengajuan cuti ditangguhkan dan pemohon telah diberi tahu.',
            'TIDAK_DISETUJUI' => 'Pengajuan cuti tidak disetujui dan pemohon telah diberi tahu.',
        };

        return redirect()->route('pimpinan.cuti.show', $leave)
            ->with('success', $message);
    }

    /** Mencatat terminal tugas dinas hanya bagi approver snapshot Pimpinan. */
    public function recordDutyPostponement(
        RecordDutyPostponementRequest $request,
        LeaveRequest $leave,
        RecordDutyPostponementAction $action,
    ) {
        $user = $request->user();
        $actor = $user?->employee;
        abort_if($user === null || $actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat memutuskan cuti.');

        $payload = $request->validated();
        $action->execute($leave, $actor, $user, $payload['active_step_id'], $payload['alasan']);

        return redirect()->route('pimpinan.cuti.show', $leave)
            ->with('success', 'Cuti Tahunan ditangguhkan karena tugas dinas dan hak terkait telah dilindungi untuk satu tahun berikutnya.');
    }
}
