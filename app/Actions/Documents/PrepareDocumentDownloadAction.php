<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PrepareDocumentDownloadAction
{
    /**
     * Menyiapkan file unduhan dengan pembatas pegawai dan kategori sesuai surface pemanggil.
     *
     * @param  list<string>|null  $allowedCategories
     * @return array{path: string, filename: string}
     */
    public function execute(string $id, ?string $employeeId = null, ?array $allowedCategories = null): array
    {
        $document = Document::query()
            ->with('employee:id,nama_lengkap')
            ->when($employeeId, fn ($query) => $query->where('employee_id', $employeeId))
            ->when($allowedCategories !== null, fn ($query) => $query->whereIn('jenis_dokumen', $allowedCategories))
            ->findOrFail($id);

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
