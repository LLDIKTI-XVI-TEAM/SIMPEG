<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Storage;

class PimpinanLeaveDocumentController extends Controller
{
    public function show(LeaveRequest $leave)
    {
        $document = $this->document($leave);

        return Storage::disk('local')->response($document['path'], $document['filename'], [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document['filename'].'"',
        ]);
    }

    public function download(LeaveRequest $leave)
    {
        $document = $this->document($leave);

        return Storage::disk('local')->download($document['path'], $document['filename'], [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function downloadAttachment(LeaveRequest $leave)
    {
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

    /** @return array{path: string, filename: string} */
    private function document(LeaveRequest $leave): array
    {
        $proof = $leave->proof;

        abort_if(
            $leave->status !== 'disetujui'
            || $proof?->document_path === null
            || ! Storage::disk('local')->exists($proof->document_path),
            404,
        );

        return [
            'path' => $proof->document_path,
            // Nama unduhan disamakan dengan formulir resmi pada halaman pegawai karena isinya kini identik.
            'filename' => 'Formulir_Cuti_'.strtoupper(substr($leave->id, 0, 8)).'.pdf',
        ];
    }
}
