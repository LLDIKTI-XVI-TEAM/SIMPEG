<?php

namespace App\Actions\Documents\Concerns;

use App\Models\Document;

trait BuildsDocumentAuditPayload
{
    /**
     * Payload audit arsip dokumen dibatasi pada metadata.
     *
     * Isi berkas tidak pernah disertakan karena arsip kepegawaian memuat data pribadi, sedangkan
     * jalur berkas dan nomor dokumen sudah cukup untuk menelusuri dokumen mana yang berubah.
     *
     * @return array<string, mixed>
     */
    private function auditPayload(Document $document): array
    {
        return [
            'employee_id' => $document->employee_id,
            'jenis_dokumen' => $document->jenis_dokumen,
            'nama_dokumen' => $document->nama_dokumen,
            'nomor_dokumen' => $document->nomor_dokumen,
            'tanggal_dokumen' => $document->tanggal_dokumen?->format('Y-m-d'),
            'file_path' => $document->file_path,
            'keterangan' => $document->keterangan,
        ];
    }
}
