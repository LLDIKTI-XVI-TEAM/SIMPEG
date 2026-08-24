<?php

namespace App\Actions\Documents\Concerns;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Penulisan berkas pada disk employee_documents (throw => false) tidak melempar
 * exception: kegagalan disk penuh/read-only/I/O dikembalikan sebagai false.
 * Semua penulisan berkas pegawai wajib lewat helper ini agar metadata tidak
 * pernah menunjuk path kosong dan salinan dokumen tidak hilang diam-diam.
 */
trait InteractsWithEmployeeDocumentStorage
{
    /**
     * Simpan file yang diunggah dan pastikan hasilnya path string valid.
     *
     * @throws ValidationException ketika penyimpanan gagal sehingga request ditolak
     *                             sebelum transaksi database dan sebelum file lama disentuh
     */
    private function storeValidatedFile(UploadedFile $file, string $directory, string $filename): string
    {
        $path = $file->storeAs($directory, $filename, Document::STORAGE_DISK);

        if (! is_string($path) || $path === '') {
            throw ValidationException::withMessages([
                'berkas' => 'Penyimpanan berkas gagal karena masalah penyimpanan server. Data tidak diubah.',
            ]);
        }

        return $path;
    }

    /** Bangun nama file standar berkas pegawai: {employee_id}_{kategori}_{uuid}.{ext} */
    private function buildEmployeeDocumentFilename(int|string $employeeId, string $category, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        return $employeeId.'_'.$category.'_'.Str::uuid().'.'.$extension;
    }
}
