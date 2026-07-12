<?php

namespace App\Actions\Cuti;

use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateLeaveProofAction
{
    public function execute(LeaveRequest $leaveRequest, ?User $generatedBy): LeaveProof
    {
        $leaveRequest->loadMissing(['employee', 'jenisCuti', 'steps.approver']);

        $proof = LeaveProof::query()->firstOrCreate(
            ['leave_request_id' => $leaveRequest->id],
            ['token' => Str::random(80)],
        );
        $finalStep = $leaveRequest->steps->firstWhere('is_final', true);
        $verificationUrl = route('cuti.verify', ['token' => $proof->token]);
        $path = 'leave-proofs/'.$leaveRequest->id.'.pdf';

        Storage::disk('local')->put($path, Pdf::loadView('pdf.leave-proof', [
            'leave' => $leaveRequest,
            'proof' => $proof,
            'finalApprover' => $finalStep?->approver,
            'decisionAt' => $finalStep?->acted_at,
            'qrDataUri' => $this->qrDataUri($verificationUrl),
            'verificationUrl' => $verificationUrl,
        ])->setPaper('a4')->output());

        $proof->forceFill([
            'document_path' => $path,
            'generated_by' => $generatedBy?->id,
            'generated_at' => now(),
        ])->save();

        return $proof;
    }

    private function qrDataUri(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(180), new SvgImageBackEnd)))
            ->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
