<?php

namespace App\Http\Controllers;

use App\Actions\Cuti\ApproveLeaveAction;
use App\Actions\Cuti\PostponeLeaveAction;
use App\Actions\Cuti\RejectLeaveAction;
use App\Actions\Cuti\RequestChangesLeaveAction;
use App\Http\Requests\Cuti\PimpinanLeaveDecisionRequest;
use App\Models\LeaveRequest;

class PimpinanLeaveDecisionController extends Controller
{
    public function store(
        PimpinanLeaveDecisionRequest $request,
        LeaveRequest $leave,
        ApproveLeaveAction $approve,
        RequestChangesLeaveAction $requestChanges,
        PostponeLeaveAction $postpone,
        RejectLeaveAction $reject,
    ) {
        $actor = $request->user()?->employee;
        abort_if($actor === null, 403, 'Akun Anda tidak tertaut ke data pegawai sehingga tidak dapat memutuskan cuti.');

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

        return redirect()->route('pimpinan.cuti.show', $leave)
            ->with('success', $message);
    }
}
