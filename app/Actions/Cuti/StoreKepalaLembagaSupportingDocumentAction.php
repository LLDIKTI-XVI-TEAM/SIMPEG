<?php

namespace App\Actions\Cuti;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\KepalaLembagaSupportingDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan dokumen Kepala Lembaga pada disk privat dengan audit yang gagal-tertutup.
 */
class StoreKepalaLembagaSupportingDocumentAction
{
    public function execute(Employee $employee, UploadedFile $file, User $actor): KepalaLembagaSupportingDocument
    {
        // Penanda eksplisit mencegah dokumen kementerian dikaitkan ke pegawai biasa karena teks jabatan.
        if (! $employee->is_kepala_lembaga) {
            throw ValidationException::withMessages([
                'employee' => 'Dokumen pendukung hanya dapat diunggah untuk pegawai yang ditandai Kepala Lembaga.',
            ]);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $storedName = (string) Str::uuid().'.'.$extension;
        $directory = KepalaLembagaSupportingDocument::PATH_PREFIX.'/'.$employee->id;

        // Nama asli hanya metadata tampilan; path selalu dibentuk server untuk mencegah traversal dan tabrakan.
        $originalName = $file->getClientOriginalName();
        $detectedMime = $file->getMimeType();
        $sizeBytes = $file->getSize();
        $storedPath = $file->storeAs(
            $directory,
            $storedName,
            KepalaLembagaSupportingDocument::STORAGE_DISK,
        );

        if ($storedPath === false) {
            throw ValidationException::withMessages([
                'berkas' => 'Gagal menyimpan berkas dokumen pendukung. Coba lagi.',
            ]);
        }

        try {
            return DB::transaction(function () use ($employee, $storedPath, $originalName, $detectedMime, $sizeBytes, $actor): KepalaLembagaSupportingDocument {
                $document = KepalaLembagaSupportingDocument::query()->create([
                    'employee_id' => $employee->id,
                    'uploaded_by' => $actor->id,
                    'stored_path' => $storedPath,
                    'original_filename' => $originalName,
                    'mime_type' => $detectedMime,
                    'size_bytes' => $sizeBytes,
                ]);

                // Audit berada dalam transaksi yang sama agar metadata tanpa jejak aktor tidak pernah aktif.
                AuditLog::query()->create([
                    'user_id' => $actor->id,
                    'user_name' => $actor->name,
                    'event' => 'CREATE',
                    'auditable_type' => 'KepalaLembagaSupportingDocument',
                    'auditable_id' => $document->id,
                    'old_values' => null,
                    'new_values' => [
                        'employee_id' => $document->employee_id,
                        'original_filename' => $document->original_filename,
                        'mime_type' => $document->mime_type,
                        'size_bytes' => $document->size_bytes,
                        'uploaded_by' => $document->uploaded_by,
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);

                return $document;
            });
        } catch (\Throwable $exception) {
            // Storage tidak transaksional; kompensasi ini mencegah file privat tanpa metadata/audit.
            Storage::disk(KepalaLembagaSupportingDocument::STORAGE_DISK)->delete($storedPath);

            throw $exception;
        }
    }
}
