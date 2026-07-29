<?php

namespace App\Actions\Cuti;

use App\Models\LeaveProof;
use App\Models\LeaveRequest;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateLeaveProofAction
{
    public function __construct(private readonly DownloadOfficialLeavePdfAction $officialPdf) {}

    public function execute(LeaveRequest $leaveRequest, ?User $generatedBy): LeaveProof
    {
        $proof = LeaveProof::query()->firstOrCreate(
            ['leave_request_id' => $leaveRequest->id],
            ['token' => Str::random(80)],
        );

        // Waktu terbit dibekukan sebelum render agar tanggal penerbitan pada dokumen
        // sama dengan metadata bukti yang dipakai verifikasi QR.
        if ($proof->wasRecentlyCreated) {
            $proof->forceFill([
                'generated_by' => $generatedBy?->id,
                'generated_at' => now(),
            ])->save();
        }

        $leaveRequest->setRelation('proof', $proof);

        // Dokumen tersimpan memakai template dan data formulir resmi yang sama dengan
        // unduhan pada halaman pegawai, sehingga seluruh permukaan (pegawai, kepala
        // bagian, pimpinan) menerima dokumen yang identik.
        $path = 'leave-proofs/'.$leaveRequest->id.'.pdf';

        Storage::disk('local')->put(
            $path,
            Pdf::loadView('admin.cuti.pdf.formulir-cuti', $this->officialPdf->viewData($leaveRequest))
                ->setPaper([0, 0, 612, 1008], 'portrait')
                ->output(),
        );

        $proof->forceFill([
            'document_path' => $path,
            'document_mime' => 'application/pdf',
        ])->save();

        return $proof;
    }
}
