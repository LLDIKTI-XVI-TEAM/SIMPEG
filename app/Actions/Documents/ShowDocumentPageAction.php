<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Support\Documents\DocumentCategory;

class ShowDocumentPageAction
{
    public function execute(string $id): array
    {
        $document = Document::with([
            'employee.positionHistories' => fn ($query) => $query
                ->with('unitKerja')
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_jabatan'),
        ])->findOrFail($id);

        $currentPosition = $document->employee->positionHistories->first();
        $unit = $currentPosition?->unitKerja?->nama ?? '-';

        return [
            'doc' => [
                'id' => $document->id,
                'nama' => $document->nama_dokumen,
                'kategori_label' => DocumentCategory::label($document->jenis_dokumen),
                'file_size' => $document->fileSizeLabel(),
                'file_extension' => $document->fileExtension(),
                'nama_pegawai' => $document->employee->nama_lengkap,
                'nip_pegawai' => $document->employee->nip,
                'unit_pegawai' => $unit,
                'nomor' => $document->nomor_dokumen ?? '-',
                'tanggal' => $document->tanggal_dokumen ? $document->tanggal_dokumen->format('Y-m-d') : '-',
                'deskripsi' => $document->keterangan ?? '-',
                'file_path' => $document->file_path,
            ],
        ];
    }
}
