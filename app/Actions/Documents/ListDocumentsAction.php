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
        $perPage    = (int) ($validated['per_page'] ?? 10);
        $filterUnit = $validated['unit_kerja'] ?? null;
        $filterStatus = $validated['status'] ?? null;

        $query = Document::with([
            'employee.positionHistories' => fn ($query) => $query
                ->with('unitKerja')
                ->orderByDesc('is_latest')
                ->orderByDesc('tmt_jabatan'),
        ]);

        // Filter yang bisa dilakukan di DB
        if (! empty($validated['search'])) {
            $keyword = '%'.mb_strtolower($validated['search']).'%';
            $query->where(function ($q) use ($keyword): void {
                $q->whereRaw('lower(nama_dokumen) like ?', [$keyword])
                    ->orWhereRaw('lower(nomor_dokumen) like ?', [$keyword]);
            });
        }

        if (! empty($validated['kategori'])) {
            $query->where('jenis_dokumen', $validated['kategori']);
        }

        $paginator = $query->latest()->paginate($perPage)->withQueryString();

        // ->through() menjaga struktur paginator tetap utuh (berbeda dari map/transform pada collection)
        return $paginator->through(function (Document $document) use ($filterUnit, $filterStatus): array {
            $currentPosition = $document->employee?->positionHistories?->first();
            $unit = $currentPosition?->unitKerja?->nama ?? '-';

            $statusDokumen = $document->fileStatus();

            // Filter unit_kerja dan status dilakukan di level PHP karena bukan kolom dokumen
            // Jika tidak cocok kembalikan array kosong yang akan di-skip di frontend
            if ($filterUnit && $unit !== $filterUnit) {
                return [];
            }

            if ($filterStatus && $statusDokumen !== $filterStatus) {
                return [];
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
        });
    }
}
