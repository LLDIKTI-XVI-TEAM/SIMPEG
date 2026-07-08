<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Support\Documents\DocumentCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListDocumentsAction
{
    /**
     * Mengambil daftar dokumen dengan filter dan paginasi server-side.
     *
     * @param  array<string, mixed>  $validated
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function execute(array $validated): LengthAwarePaginator
    {
        $perPage = (int) ($validated['per_page'] ?? 10);

        return Document::with([
            'employee.positionHistories' => fn ($query) => $query
                ->with('unitKerja')
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_jabatan'),
        ])
            ->when(
                $validated['search'] ?? null,
                function ($query, string $search): void {
                    $keyword = '%'.mb_strtolower($search).'%';
                    $query->where(function ($q) use ($keyword): void {
                        $q->whereRaw('lower(nama_dokumen) like ?', [$keyword])
                            ->orWhereRaw('lower(nomor_dokumen) like ?', [$keyword]);
                    });
                }
            )
            ->when(
                $validated['kategori'] ?? null,
                fn ($query, string $kategori) => $query->where('jenis_dokumen', $kategori)
            )
            ->latest()
            ->paginate($perPage)
            ->withQueryString()
            ->through(function (Document $document) use ($validated): ?array {
                // Filter unit kerja: harus dilakukan setelah eager load karena unit_kerja bukan kolom dokumen
                $currentPosition = $document->employee?->positionHistories?->first();
                $unit = $currentPosition?->unitKerja?->nama ?? '-';

                $filterUnit = $validated['unit_kerja'] ?? null;
                if ($filterUnit && $unit !== $filterUnit) {
                    return null;
                }

                $statusDokumen = $document->fileStatus();

                // Filter status dokumen
                $filterStatus = $validated['status'] ?? null;
                if ($filterStatus && $statusDokumen !== $filterStatus) {
                    return null;
                }

                return [
                    'id'             => $document->id,
                    'jenis'          => DocumentCategory::label($document->jenis_dokumen),
                    'nama'           => $document->nama_dokumen,
                    'nomor'          => $document->nomor_dokumen ?? '-',
                    'tanggal'        => $document->tanggal_dokumen ? $document->tanggal_dokumen->format('Y-m-d') : '-',
                    'kategori'       => $document->jenis_dokumen,
                    'kategori_label' => DocumentCategory::label($document->jenis_dokumen),
                    'nama_pegawai'   => $document->employee?->nama_lengkap ?? 'Pegawai Nonaktif',
                    'nip_pegawai'    => $document->employee?->nip ?? '-',
                    'foto_pegawai'   => $document->employee?->foto_url ?? null,
                    'unit_pegawai'   => $unit,
                    'file_path'      => $document->file_path,
                    'file_size'      => $document->fileSizeLabel(),
                    'status_dokumen' => $statusDokumen,
                    'status_label'   => $document->fileStatusLabel(),
                    'deskripsi'      => $document->keterangan ?? '',
                ];
            })
            ->filter(fn (?array $item) => $item !== null)
            ->values();
    }
}
