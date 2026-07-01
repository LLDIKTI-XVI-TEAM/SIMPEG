<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
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

    public function deletePublicFile(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('public')->delete($path);
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
        $path = $directory.'/'.Str::uuid().'.'.$extension;
        $contents = file_get_contents($file->getRealPath());
        $targetPath = Storage::disk($disk)->path($path);
        $targetDirectory = dirname($targetPath);

        File::ensureDirectoryExists($targetDirectory);

        if ($contents === false || file_put_contents($targetPath, $contents) === false) {
            throw new \RuntimeException('Gagal menyimpan file upload pegawai.');
        }

        return $path;
    }
}
