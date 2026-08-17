<?php

namespace App\Support\Documents;

use App\Models\Document;
use App\Models\EmployeeStatusHistory;
use Illuminate\Database\Eloquent\Collection;

final class LegacyStatusDocumentResolver
{
    /**
     * Memilih SK status legacy pertama yang masih tersedia dari kandidat yang sudah dibatasi pemilik.
     *
     * Metadata duplikat dapat tersisa dari data lama; file yang hilang dilewati agar tautan detail
     * dan unduhan backend selalu menunjuk kandidat privat yang sama dan benar-benar dapat dibaca.
     *
     * @param  Collection<int, Document>  $documents
     */
    public static function resolve(Collection $documents, EmployeeStatusHistory $history): ?Document
    {
        if (! is_string($history->nomor_berkas) || $history->nomor_berkas === '') {
            return null;
        }

        return $documents
            ->filter(fn (Document $document): bool => $document->jenis_dokumen === 'sk_status_pegawai'
                && $document->nomor_dokumen === $history->nomor_berkas)
            ->sortBy('id')
            ->first(fn (Document $document): bool => $document->fileExists());
    }
}
