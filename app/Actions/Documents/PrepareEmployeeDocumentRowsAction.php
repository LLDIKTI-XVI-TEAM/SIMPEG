<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\Employee;
use App\Support\Documents\DocumentCategory;
use Illuminate\Support\Collection;

class PrepareEmployeeDocumentRowsAction
{
    /**
     * Menyiapkan payload presentasi tanpa query dari Blade.
     *
     * Kontrol mutasi hanya ditawarkan untuk dokumen tambahan standalone. Lampiran
     * riwayat tetap terlihat, tetapi harus dikelola melalui alur riwayat asalnya.
     *
     * @return array{sk: list<array<string, mixed>>, others: list<array<string, mixed>>}
     */
    public function execute(Employee $employee): array
    {
        $historyPaths = collect([
            ...$employee->rankHistories->pluck('file_sk'),
            ...$employee->positionHistories->pluck('file_sk'),
            ...$employee->salaryHistories->pluck('file_sk'),
            ...$employee->statusHistories->pluck('file_sk'),
            ...$employee->disciplineRecords->pluck('file_sk'),
            ...$employee->educationHistories->pluck('file_ijazah'),
            $employee->appointment?->file_sk,
            $employee->status_berkas_path,
        ])->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->flip();

        /** @var Collection<int, Document> $documents */
        $documents = $employee->documents;
        $rows = $documents->map(fn (Document $document): array => $this->toRow($document, $historyPaths));

        return [
            'sk' => $rows
                ->filter(fn (array $row): bool => DocumentCategory::isProtectedSk($row['jenis_dokumen']))
                ->values()
                ->all(),
            'others' => $rows
                ->reject(fn (array $row): bool => DocumentCategory::isProtectedSk($row['jenis_dokumen']))
                ->values()
                ->all(),
        ];
    }

    /** @param Collection<string, int> $historyPaths */
    private function toRow(Document $document, Collection $historyPaths): array
    {
        return [
            'id' => $document->id,
            'nama_dokumen' => $document->nama_dokumen,
            'jenis_dokumen' => $document->jenis_dokumen,
            'kategori_label' => DocumentCategory::label($document->jenis_dokumen),
            'nomor_dokumen' => $document->nomor_dokumen,
            'tanggal_dokumen' => $document->tanggal_dokumen?->format('d-m-Y'),
            'tanggal_input' => $document->tanggal_dokumen?->format('Y-m-d'),
            'file_size' => $document->fileSizeLabel(),
            'file_tersedia' => $document->fileExists(),
            'keterangan' => $document->keterangan,
            'detail_url' => route('dokumen.show', $document->id),
            'download_url' => route('dokumen.download', $document->id),
            'can_mutate' => DocumentCategory::isOtherUpload($document->jenis_dokumen)
                && ! $historyPaths->has($document->file_path),
        ];
    }
}
