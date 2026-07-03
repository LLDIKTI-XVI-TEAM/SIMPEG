<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareDocumentDownloadAction
{
    public function execute(string $id): array
    {
        $document = Document::with('employee')->findOrFail($id);

        if (! Storage::disk(Document::STORAGE_DISK)->exists($document->file_path)) {
            abort(404);
        }

        $extension = pathinfo($document->file_path, PATHINFO_EXTENSION);
        $employeeName = $document->employee ? $document->employee->nama_lengkap : 'pegawai';
        $filename = Str::slug($employeeName).'-'.Str::slug($document->nama_dokumen).'.'.$extension;

        return [
            'path' => $document->file_path,
            'filename' => $filename,
        ];
    }
}
