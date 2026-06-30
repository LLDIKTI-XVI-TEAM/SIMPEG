<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;

class StoreDocumentAction
{
    public function execute(array $payload, UploadedFile $file): Document
    {
        $employee = Employee::findOrFail($payload['pegawai_id']);
        $category = $payload['kategori_dokumen'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = $employee->id.'_'.$category.'_'.now()->format('YmdHis').'.'.$extension;
        $filePath = $file->storeAs($employee->id.'/'.$category, $filename, Document::STORAGE_DISK);

        return Document::create([
            'employee_id' => $employee->id,
            'jenis_dokumen' => $category,
            'nama_dokumen' => $payload['nama_dokumen'],
            'nomor_dokumen' => $payload['nomor_dokumen'] ?? null,
            'tanggal_dokumen' => $payload['tanggal_terbit'] ?? null,
            'file_path' => $filePath,
            'keterangan' => $payload['deskripsi'] ?? null,
        ]);
    }
}
