<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmployeeFileStorageService
{
    public function storePhoto(UploadedFile $file): string
    {
        return $this->store($file, 'employees/photos');
    }

    public function storeSk(UploadedFile $file): string
    {
        return $this->storePrivate($file, 'sk');
    }

    /**
     * Menyimpan lampiran pendukung pengajuan cuti (mis. surat keterangan).
     * Disimpan terpisah pada folder cuti agar berkas cuti tidak tercampur dengan dokumen pegawai lain.
     */
    public function storeLampiran(UploadedFile $file): string
    {
        return $this->store($file, 'cuti');
    }

    /**
     * Menyimpan berkas lainnya (KTP, KK, SK Mutasi, SK Pensiun, dsb.)
     * ke disk publik per folder employee agar bisa diakses via URL /storage.
     */
    public function storeBerkasLainnya(UploadedFile $file, string $employeeId): string
    {
        return $this->store($file, "berkas/{$employeeId}");
    }

    public function deletePublicFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Gagal menghapus file publik pegawai.', ['path' => $path]);
            }
        } catch (\Throwable $exception) {
            // Kegagalan kompensasi storage tidak boleh menutupi exception transaksi yang menjadi akar masalah.
            Log::warning('Gagal menghapus file publik pegawai.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function store(UploadedFile $file, string $directory): string
    {
        return $this->storeOnDisk($file, $directory, 'public');
    }

    private function storePrivate(UploadedFile $file, string $directory): string
    {
        return $this->storeOnDisk($file, $directory, Document::STORAGE_DISK);
    }

    private function storeOnDisk(UploadedFile $file, string $directory, string $disk): string
    {
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $filename = Str::uuid().'.'.$extension;
        $path = $file->storeAs($directory, $filename, ['disk' => $disk]);

        if ($path === false) {
            throw new \RuntimeException('Gagal menyimpan file upload pegawai.');
        }

        return $path;
    }
}
