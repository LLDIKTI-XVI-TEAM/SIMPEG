<?php

namespace App\Http\Controllers;

use App\Models\LeaveProof;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

class LeaveVerificationController extends Controller
{
    public function show(string $token): View|Response
    {
        $proof = LeaveProof::query()
            ->with(['leaveRequest.employee', 'leaveRequest.jenisCuti', 'leaveRequest.steps.approver'])
            ->where('token', $token)
            ->first();

        if ($proof === null || $proof->leaveRequest?->status !== 'disetujui') {
            return response()->view('leave.verify', ['proof' => null], 404);
        }

        $finalStep = $proof->leaveRequest->steps->firstWhere('is_final', true);

        return view('leave.verify', [
            'proof' => $proof,
            'finalApprover' => $finalStep?->approver,
            'decisionAt' => $finalStep?->acted_at,
        ]);
    }
}
