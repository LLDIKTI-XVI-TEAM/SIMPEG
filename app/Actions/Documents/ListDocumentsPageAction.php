<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Support\Documents\DocumentCategory;

class ListDocumentsPageAction
{
    public function execute(): array
    {
        $pegawaiList = Employee::orderBy('nama_lengkap')->get(['id', 'nama_lengkap', 'nip']);
        $documents = Document::with([
            'employee.positionHistories' => fn ($query) => $query
                ->with('unitKerja')
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_jabatan'),
        ])->latest()->get();

        return [
            'pegawaiList' => $pegawaiList,
            'documents' => $documents,
            'pegawaiOptions' => $pegawaiList->map(fn (Employee $pegawai) => [
                'id' => $pegawai->id,
                'label' => $pegawai->nama_lengkap.' (NIP. '.$pegawai->nip.')',
            ])->values(),
            'categoryLabels' => DocumentCategory::labels(),
            'documentsForTable' => $documents->map(function (Document $document): array {
                $currentPosition = $document->employee?->positionHistories?->first();
                $unit = $currentPosition?->unitKerja?->nama ?? '-';

                return [
                    'id' => $document->id,
                    'jenis' => DocumentCategory::label($document->jenis_dokumen),
                    'nama' => $document->nama_dokumen,
                    'nomor' => $document->nomor_dokumen ?? '-',
                    'tanggal' => $document->tanggal_dokumen ? $document->tanggal_dokumen->format('Y-m-d') : '-',
                    'kategori' => $document->jenis_dokumen,
                    'kategori_label' => DocumentCategory::label($document->jenis_dokumen),
                    'nama_pegawai' => $document->employee?->nama_lengkap ?? 'Pegawai Nonaktif',
                    'nip_pegawai' => $document->employee?->nip ?? '-',
                    'foto_pegawai' => $document->employee?->foto_url ?? null,
                    'unit_pegawai' => $unit,
                    'file_path' => $document->file_path,
                    'file_size' => $document->fileSizeLabel(),
                    'status_dokumen' => $document->fileStatus(),
                    'status_label' => $document->fileStatusLabel(),
                    'deskripsi' => $document->keterangan ?? '',
                ];
            })->values(),
        ];
    }
}
