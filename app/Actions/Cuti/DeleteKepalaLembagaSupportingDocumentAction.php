<?php

namespace App\Actions\Cuti;

use App\Models\AuditLog;
use App\Models\KepalaLembagaSupportingDocument;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Membuat tombstone dan audit durable sebelum mencoba menghapus berkas fisik.
 */
class DeleteKepalaLembagaSupportingDocumentAction
{
    public function execute(KepalaLembagaSupportingDocument $document, User $actor): void
    {
        $document->loadMissing('employee');

        // Dokumen ditutup bila status Kepala Lembaga dicabut, termasuk untuk operasi delete.
        if (! $document->employee?->is_kepala_lembaga) {
            abort(404);
        }

        $storedPath = $document->stored_path;

        DB::transaction(function () use ($document, $actor): void {
            $document->delete();

            // Snapshot audit sengaja tidak menyimpan path privat.
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'user_name' => $actor->name,
                'event' => 'SOFT_DELETE',
                'auditable_type' => 'KepalaLembagaSupportingDocument',
                'auditable_id' => $document->id,
                'old_values' => [
                    'employee_id' => $document->employee_id,
                    'original_filename' => $document->original_filename,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size_bytes,
                ],
                'new_values' => null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });

        try {
            /** @var Filesystem $disk */
            $disk = Storage::disk(KepalaLembagaSupportingDocument::STORAGE_DISK);

            if ($disk->exists($storedPath)) {
                $disk->delete($storedPath);
            }
        } catch (\Throwable $exception) {
            // Tombstone menyimpan path untuk retry operasional; audit durable tidak boleh dibatalkan.
            Log::warning('Gagal menghapus file fisik dokumen pendukung Kepala Lembaga; tombstone dipertahankan untuk retry.', [
                'document_id' => $document->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
