<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Cuti\DecideLeaveCancellationAction;
use App\Actions\Cuti\ListLeaveCancellationRequestsAction;
use App\Actions\Cuti\RequestLeaveCancellationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\DecideLeaveCancellationRequest;
use App\Http\Requests\Cuti\ListLeaveCancellationRequest;
use App\Http\Requests\Cuti\RequestLeaveCancellationRequest;
use App\Models\LeaveCancellationRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/** Adapter tipis untuk permintaan pembatalan oleh pemilik pengajuan cuti. */
class LeaveCancellationController extends Controller
{
    /** Menampilkan antrean pembatalan terbatas untuk Admin Kepegawaian yang berwenang. */
    public function index(
        ListLeaveCancellationRequest $request,
        ListLeaveCancellationRequestsAction $action,
    ): View {
        return view('admin.cuti.cancellations.index', [
            'cancellations' => $action->execute($request->validated()),
            'filters' => $request->validated(),
        ]);
    }

    public function store(
        RequestLeaveCancellationRequest $request,
        LeaveRequest $leaveRequest,
        RequestLeaveCancellationAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $payload = $request->validated();

        $action->execute($leaveRequest, $actor, $payload['reason'], $request);

        return redirect()->route('cuti.show', $leaveRequest)
            ->with('success', 'Permohonan pembatalan cuti telah dikirim dan menunggu keputusan Admin Kepegawaian.');
    }

    /** Meneruskan keputusan Admin ke Action agar mutasi, audit, dan notifikasi tetap satu use case. */
    public function decide(
        DecideLeaveCancellationRequest $request,
        LeaveCancellationRequest $cancellation,
        DecideLeaveCancellationAction $action,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $decided = $action->execute($cancellation, $actor, (string) $request->validated('decision'), $request);

        return redirect()->route('cuti.cancellations.index')
            ->with(
                'success',
                $decided->status === LeaveCancellationRequest::STATUS_APPROVED
                    ? 'Permohonan pembatalan cuti telah disetujui.'
                    : 'Permohonan pembatalan cuti ditolak dan approval dilanjutkan.',
            );
    }
}
