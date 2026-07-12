<?php

namespace App\Actions\Documents;

use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UpdateDocumentAction
{
    public function execute(Document $document, array $payload, ?UploadedFile $file = null): Document
    {
        return DB::transaction(function () use ($document, $payload, $file): Document {
            $oldFilePath = $document->file_path;
            $oldNomorSk = $document->nomor_dokumen;
            
            $filePath = $oldFilePath;

            if ($file) {
                // Hapus file lama jika ada
                if (Storage::disk(Document::STORAGE_DISK)->exists($oldFilePath)) {
                    Storage::disk(Document::STORAGE_DISK)->delete($oldFilePath);
                }

                $category = $payload['kategori_dokumen'];
                $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
                $filename = $document->employee_id.'_'.$category.'_'.now()->format('YmdHis').'.'.$extension;
                
                // Simpan file baru
                $filePath = $file->storeAs($document->employee_id.'/'.$category, $filename, Document::STORAGE_DISK);
            }

            $document->update([
                'jenis_dokumen' => $payload['kategori_dokumen'],
                'nama_dokumen' => $payload['nama_dokumen'],
                'nomor_dokumen' => $payload['nomor_dokumen'] ?? null,
                'tanggal_dokumen' => $payload['tanggal_terbit'] ?? null,
                'file_path' => $filePath,
                'keterangan' => $payload['deskripsi'] ?? null,
            ]);

            // Jika nomor SK atau file berubah, kita juga perlu mengupdate riwayat yang terhubung
            // (yang awalnya memiliki nomor SK / file yang sama dengan dokumen ini)
            $newNomorSk = $document->nomor_dokumen;
            
            if (($oldNomorSk && $oldNomorSk !== $newNomorSk) || $oldFilePath !== $filePath) {
                // Update Kenaikan Pangkat
                RankHistory::where('employee_id', $document->employee_id)
                    ->where('no_sk', $oldNomorSk)
                    ->update([
                        'no_sk' => $newNomorSk,
                        'file_sk' => $filePath,
                    ]);

                // Update Jabatan
                PositionHistory::where('employee_id', $document->employee_id)
                    ->where('no_sk', $oldNomorSk)
                    ->update([
                        'no_sk' => $newNomorSk,
                        'file_sk' => $filePath,
                    ]);

                // Update Hukuman Disiplin
                DisciplineRecord::where('employee_id', $document->employee_id)
                    ->where('no_sk', $oldNomorSk)
                    ->update([
                        'no_sk' => $newNomorSk,
                        'file_sk' => $filePath,
                    ]);

                // Update KGB
                SalaryHistory::where('employee_id', $document->employee_id)
                    ->where('no_sk', $oldNomorSk)
                    ->update([
                        'no_sk' => $newNomorSk,
                        'file_sk' => $filePath,
                    ]);
            }

            return $document;
        });
    }
}
