<?php

namespace App\Actions\Employees;

use App\Models\Document;
use App\Models\Employee;

class ListEmployeeArchiveOptionsAction
{
    /**
     * Memuat satu halaman metadata SK; pemilihan arsip tidak membuka path berkas privat.
     *
     * @param  array{kategori: string, q?: string|null, page?: int|string|null}  $filters
     * @return array<string, mixed>
     */
    public function execute(Employee $employee, array $filters): array
    {
        $search = trim($filters['q'] ?? '');
        $pattern = '%'.addcslashes($search, '\\%_').'%';
        $paginator = $employee->documents()
            ->where('jenis_dokumen', $filters['kategori'])
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->whereLike('nama_dokumen', $pattern)
                ->orWhereLike('nomor_dokumen', $pattern)))
            ->orderByRaw('tanggal_dokumen DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate(10, ['id', 'nomor_dokumen', 'tanggal_dokumen', 'nama_dokumen'], 'page', (int) ($filters['page'] ?? 1));

        return [
            'data' => $paginator->getCollection()->map(fn (Document $document) => [
                'id' => $document->id,
                'label' => ($document->nomor_dokumen ?? 'Tanpa No.').($document->tanggal_dokumen ? ' — '.$document->tanggal_dokumen->format('d/m/Y') : ''),
                'nomor_dokumen' => $document->nomor_dokumen,
                'tanggal_dokumen' => $document->tanggal_dokumen?->format('Y-m-d'),
                'nama_dokumen' => $document->nama_dokumen,
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
