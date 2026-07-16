<?php

namespace App\Actions\Cuti;

use App\Models\KepalaLembagaSupportingDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Menyajikan dokumen privat setelah target dan keberadaan file diverifikasi ulang.
 */
class PrepareKepalaLembagaSupportingDocumentResponseAction
{
    /** @param 'inline'|'attachment' $disposition */
    public function execute(KepalaLembagaSupportingDocument $document, string $disposition = 'attachment'): StreamedResponse
    {
        $document->loadMissing('employee');

        // Marker diperiksa saat setiap read agar dokumen lama langsung tertutup ketika status target berubah.
        if (! $document->employee?->is_kepala_lembaga) {
            abort(404);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk(KepalaLembagaSupportingDocument::STORAGE_DISK);

        if (! $disk->exists($document->stored_path)) {
            abort(404);
        }

        $inlineAllowed = in_array($document->mime_type, [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ], true);
        $effectiveDisposition = $disposition === 'inline' && $inlineAllowed
            ? 'inline'
            : 'attachment';

        return $disk->response(
            $document->stored_path,
            $document->original_filename,
            [
                'Content-Type' => $document->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
            $effectiveDisposition,
        );
    }
}
