<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cuti\PreviewLeaveBalanceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cuti\PreviewLeaveBalanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Preview saldo untuk form pengajuan cuti Pegawai yang sedang login.
 * Controller hanya menghubungkan request tervalidasi dengan Action; data scope
 * ditetapkan dari sesi, bukan dari parameter klien.
 */
class LeaveBalancePreviewController extends Controller
{
    public function __invoke(PreviewLeaveBalanceRequest $request, PreviewLeaveBalanceAction $action): JsonResponse
    {
        $employee = $request->user()->employee;

        return response()->json([
            'data' => $action->execute(
                $employee,
                Carbon::parse($request->validated('tanggal_mulai')),
            ),
        ]);
    }
}
