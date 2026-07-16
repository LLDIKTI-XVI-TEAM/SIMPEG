<?php

namespace App\Actions\Cuti;

use App\Models\KepalaLembagaSupportingDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Mencoba ulang penghapusan file milik tombstone tanpa menulis audit pengguna kedua.
 */
class PurgeKepalaLembagaSupportingDocumentFilesAction
{
    /** @return int Jumlah file fisik yang berhasil dihapus pada eksekusi ini. */
    public function execute(): int
    {
        /** @var Filesystem $disk */
        $disk = Storage::disk(KepalaLembagaSupportingDocument::STORAGE_DISK);
        $purged = 0;

        KepalaLembagaSupportingDocument::onlyTrashed()
            ->orderBy('deleted_at')
            ->chunkById(100, function ($documents) use ($disk, &$purged): void {
                foreach ($documents as $document) {
                    try {
                        if (! $disk->exists($document->stored_path)) {
                            continue;
                        }

                        if (! $disk->delete($document->stored_path)) {
                            Log::warning('Retry hapus file dokumen pendukung Kepala Lembaga gagal.', [
                                'document_id' => $document->id,
                            ]);

                            continue;
                        }

                        $purged++;
                    } catch (\Throwable $exception) {
                        // Tombstone tetap dipertahankan agar operasi berikutnya dapat mencoba ulang.
                        Log::warning('Retry hapus file dokumen pendukung Kepala Lembaga gagal.', [
                            'document_id' => $document->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $purged;
    }
}
