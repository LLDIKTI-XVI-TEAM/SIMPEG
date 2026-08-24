<?php

namespace App\Services\Documents;

use App\Jobs\CleanupEmployeeDocumentFileJob;
use App\Models\Document;
use App\Support\Documents\BerkasLainnyaMutationGuard;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EmployeeDocumentFileCleanupService
{
    public function __construct(private readonly BerkasLainnyaMutationGuard $guard) {}

    /**
     * Bersihkan file privat setelah metadata selesai commit.
     *
     * Referensi diperiksa sebelum penghapusan agar path bersama tetap aman. Kegagalan
     * storage dijadwalkan ulang tanpa mencatat path privat mentah ke log aplikasi.
     */
    public function deleteOrScheduleRetry(string $filePath): void
    {
        if ($this->guard->fileIsStillReferenced($filePath)) {
            return;
        }

        if (Storage::disk(Document::STORAGE_DISK)->delete($filePath)) {
            return;
        }

        Log::warning('Penghapusan file dokumen pegawai ditunda karena storage gagal.', [
            'file_path_hash' => hash('sha256', $filePath),
        ]);
        CleanupEmployeeDocumentFileJob::dispatch($filePath);
    }
}
