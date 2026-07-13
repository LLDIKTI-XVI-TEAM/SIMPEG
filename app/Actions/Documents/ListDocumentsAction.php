<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Support\Documents\DocumentCategory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

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
        $filterUnit = $validated['unit_kerja'] ?? null;
        $filterStatus = $validated['status'] ?? null;

        $query = Document::query()
            ->select([
                'documents.id',
                'documents.employee_id',
                'documents.jenis_dokumen',
                'documents.nama_dokumen',
                'documents.nomor_dokumen',
                'documents.tanggal_dokumen',
                'documents.file_path',
                'documents.keterangan',
                'documents.created_at',
            ])
            ->with([
                // Hanya employee data ringkas — tidak perlu load semua relasi
                'employee:id,nama_lengkap,nip,foto',
            ]);

        // Filter unit_kerja via JOIN ke position_histories agar dilakukan di DB, bukan PHP
        if (! empty($filterUnit)) {
            $query->whereHas('employee.positionHistories', function ($q) use ($filterUnit): void {
                $q->where('is_latest', true)
                    ->whereHas('unitKerja', fn ($uq) => $uq->where('nama', $filterUnit));
            });
            // Eager load unit_kerja hanya jika filter aktif (sudah pasti ada)
            $query->with(['employee.positionHistories' => fn ($q) => $q
                ->with('unitKerja:id,nama')
                ->where('is_latest', true)
                ->limit(1),
            ]);
        }

        // Filter status dokumen (file exists/tidak) — dilakukan di PHP karena filesystem check,
        // tapi hanya setelah paginate agar tidak tarik semua row
        // (dokumentasi: filter ini memang tidak bisa di-push ke DB)

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

        $paginator = $query->latest('documents.created_at')->paginate($perPage)->withQueryString();

        // Batch-check file existence sekaligus — satu operasi disk bukan N operasi
        $disk = Storage::disk(Document::STORAGE_DISK);
        $filePaths = $paginator->pluck('file_path')->filter()->unique()->values()->all();
        $existingMap = [];
        foreach ($filePaths as $path) {
            $existingMap[$path] = $disk->exists($path);
        }

        // Eager load positionHistories hanya jika unit_kerja filter tidak aktif
        // (kalau aktif sudah di-load di atas)
        if (empty($filterUnit)) {
            /** @var Collection<int, Document> $collection */
            $collection = $paginator->getCollection();
            $collection->loadMissing(['employee.positionHistories' => fn ($q) => $q
                ->with('unitKerja:id,nama')
                ->where('is_latest', true)
                ->limit(1),
            ]);
        }

        /** @var \Illuminate\Pagination\LengthAwarePaginator<int, array<string, mixed>> $result */
        $result = $paginator->through(function (Document $document) use ($filterStatus, $existingMap, $disk): array {
            $currentPosition = $document->employee?->positionHistories?->first();
            $unit = $currentPosition?->unitKerja?->nama ?? '-';

            $fileExists = $existingMap[$document->file_path] ?? false;
            $statusDokumen = $fileExists ? 'tersedia' : 'file_tidak_ditemukan';

            if ($filterStatus && $statusDokumen !== $filterStatus) {
                return [];
            }

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
                'file_size' => $this->fileSizeLabel($document->file_path, $fileExists, $disk),
                'status_dokumen' => $statusDokumen,
                'status_label' => $fileExists ? 'File tersedia' : 'File tidak ditemukan',
                'deskripsi' => $document->keterangan ?? '',
                // Extra fields used by edit modal
                'employee_id' => $document->employee_id,
                'jenis_dokumen' => $document->jenis_dokumen,
                'nama_dokumen' => $document->nama_dokumen,
                'nomor_dokumen' => $document->nomor_dokumen,
                'tanggal_dokumen' => $document->tanggal_dokumen?->format('Y-m-d'),
                'keterangan' => $document->keterangan,
            ];
        });

        return $result;
    }

    /**
     * Hitung label ukuran file dari hasil batch disk check yang sudah ada.
     */
    private function fileSizeLabel(string $path, bool $exists, Filesystem $disk): string
    {
        if (! $exists) {
            return 'File tidak ditemukan';
        }

        try {
            $bytes = $disk->size($path);
        } catch (\Throwable) {
            return 'File tidak ditemukan';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.').' MB';
        }

        return max(1, (int) ceil($bytes / 1024)).' KB';
    }
}
