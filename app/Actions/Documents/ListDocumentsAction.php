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

        $searchTerm = trim((string) ($validated['search'] ?? ''));
        if ($searchTerm !== '') {
            $keyword = '%'.mb_strtolower($searchTerm).'%';

            // Label kategori (mis. "KTP & KK", "SK KGB") tidak tersimpan apa adanya di
            // kolom jenis_dokumen — petakan label yang cocok ke kuncinya agar pencarian
            // berdasarkan nama kategori tetap menemukan dokumennya. Arah satu saja
            // (label memuat istilah) supaya konsisten dengan semantik LIKE kolom lain
            // dan istilah pendek tidak meledak ke seluruh kategori.
            $matchedCategoryKeys = [];
            $lowerTerm = mb_strtolower($searchTerm);
            foreach (DocumentCategory::labels() as $categoryKey => $categoryLabel) {
                if (str_contains(mb_strtolower($categoryLabel), $lowerTerm)) {
                    $matchedCategoryKeys[] = $categoryKey;
                }
            }

            $query->where(function ($q) use ($keyword, $matchedCategoryKeys): void {
                $q->whereRaw('lower(documents.nama_dokumen) like ?', [$keyword])
                    ->orWhereRaw('lower(documents.nomor_dokumen) like ?', [$keyword])
                    ->orWhereRaw('lower(documents.jenis_dokumen) like ?', [$keyword])
                    ->orWhereHas('employee', function ($employeeQuery) use ($keyword): void {
                        $employeeQuery->whereRaw('lower(nama_lengkap) like ?', [$keyword])
                            ->orWhereRaw('lower(nip) like ?', [$keyword]);
                    });

                if ($matchedCategoryKeys !== []) {
                    $q->orWhereIn('documents.jenis_dokumen', $matchedCategoryKeys);
                }
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

        /** @var Collection<int, Document> $collection */
        $collection = $paginator->getCollection();
        $collection->loadMissing(['employee.positionHistories' => fn ($q) => $q
            ->with('unitKerja:id,nama')
            ->where('is_latest', true)
            ->limit(1),
        ]);

        /** @var \Illuminate\Pagination\LengthAwarePaginator<int, array<string, mixed>> $result */
        $result = $paginator->through(function (Document $document) use ($existingMap, $disk): array {
            $currentPosition = $document->employee?->positionHistories?->first();
            $unit = $currentPosition?->unitKerja?->nama ?? '-';

            $fileExists = $existingMap[$document->file_path] ?? false;
            $statusDokumen = $fileExists ? 'tersedia' : 'file_tidak_ditemukan';

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
