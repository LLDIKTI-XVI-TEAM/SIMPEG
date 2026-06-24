<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmployeeFileStorageService
{
    public function storePhoto(UploadedFile $file): string
    {
        return $this->store($file, 'photos');
    }

    public function storeSk(UploadedFile $file): string
    {
        return $this->store($file, 'sk');
    }

    public function deletePublicFile(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }

    private function store(UploadedFile $file, string $directory): string
    {
        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $path = $directory.'/'.Str::uuid().'.'.$extension;
        $contents = file_get_contents($file->getRealPath());
        $targetPath = Storage::disk('public')->path($path);
        $targetDirectory = dirname($targetPath);

        File::ensureDirectoryExists($targetDirectory);

        if ($contents === false || file_put_contents($targetPath, $contents) === false) {
            throw new \RuntimeException('Gagal menyimpan file upload pegawai.');
        }

        return $path;
    }
}
