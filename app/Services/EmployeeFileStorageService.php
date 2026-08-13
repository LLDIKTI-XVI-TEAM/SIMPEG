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
        return $this->storeEmployeeDocument($file, 'sk');
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
     * Menyimpan berkas lainnya tanpa URL publik; akses file wajib melalui route berotorisasi.
     */
    public function storeBerkasLainnya(UploadedFile $file, string $employeeId): string
    {
        return $this->storeEmployeeDocument($file, "berkas/{$employeeId}");
    }

    /**
     * Menyimpan dokumen pegawai ke disk khusus privat agar tidak dapat dilewati melalui symlink publik.
     */
    public function storeEmployeeDocument(UploadedFile $file, string $directory): string
    {
        return $this->storeOnDisk($file, $directory, Document::STORAGE_DISK);
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

    public function deleteEmployeeDocumentFile(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        try {
            if (! Storage::disk(Document::STORAGE_DISK)->delete($path)) {
                Log::warning('Gagal menghapus dokumen privat pegawai.', ['path' => $path]);
            }
        } catch (\Throwable $exception) {
            Log::warning('Gagal menghapus dokumen privat pegawai.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function store(UploadedFile $file, string $directory): string
    {
        return $this->storeOnDisk($file, $directory, 'public');
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
