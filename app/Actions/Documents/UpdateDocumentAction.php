<?php

namespace App\Actions\Documents;

use App\Models\Appointment;
use App\Models\DisciplineRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\PositionHistory;
use App\Models\RankHistory;
use App\Models\SalaryHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class UpdateDocumentAction
{
    /**
     * Ganti metadata dokumen dan, jika ada, file fisiknya.
     *
     * File baru selalu disimpan lebih dahulu. File lama hanya dihapus setelah
     * transaksi database berhasil, sehingga arsip lama tidak hilang bila proses
     * unggah atau pembaruan database gagal.
     *
     * @param  array<string, mixed>  $payload
     */
    public function execute(Document $document, array $payload, ?UploadedFile $file = null): Document
    {
        $disk = Storage::disk(Document::STORAGE_DISK);
        $replacementPath = null;

        try {
            if ($file !== null) {
                $replacementPath = $this->storeReplacementFile($document, $payload['kategori_dokumen'], $file);
            }

            [$updatedDocument, $oldFilePath] = DB::transaction(function () use ($document, $payload, $replacementPath): array {
                /** @var Document $lockedDocument */
                $lockedDocument = Document::query()->lockForUpdate()->findOrFail($document->id);
                $oldFilePath = $lockedDocument->file_path;
                $oldNomorSk = $lockedDocument->nomor_dokumen;
                $oldCategory = $lockedDocument->jenis_dokumen;
                $filePath = $replacementPath ?? $oldFilePath;

                $lockedDocument->update([
                    'jenis_dokumen' => $payload['kategori_dokumen'],
                    'nama_dokumen' => $payload['nama_dokumen'],
                    'nomor_dokumen' => $payload['nomor_dokumen'] ?? null,
                    'tanggal_dokumen' => $payload['tanggal_terbit'] ?? null,
                    'file_path' => $filePath,
                    'keterangan' => $payload['deskripsi'] ?? null,
                ]);

                $this->syncRelatedHistories(
                    $lockedDocument,
                    $oldFilePath,
                    $oldNomorSk,
                    $oldCategory,
                );

                return [$lockedDocument->refresh(), $oldFilePath];
            });
        } catch (Throwable $exception) {
            if ($replacementPath !== null) {
                $disk->delete($replacementPath);
            }

            throw $exception;
        }

        if ($replacementPath !== null
            && $oldFilePath !== $replacementPath
            && ! $this->fileIsStillReferenced($oldFilePath)) {
            $disk->delete($oldFilePath);
        }

        return $updatedDocument;
    }

    private function storeReplacementFile(Document $document, string $category, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = $document->employee_id.'_'.$category.'_'.Str::uuid().'.'.$extension;

        return $file->storeAs($document->employee_id.'/'.$category, $filename, Document::STORAGE_DISK);
    }

    private function syncRelatedHistories(
        Document $document,
        string $oldFilePath,
        ?string $oldNomorSk,
        string $oldCategory,
    ): void {
        $updates = [
            'no_sk' => $document->nomor_dokumen,
            'file_sk' => $document->file_path,
        ];

        $this->matchingHistoryRecords(RankHistory::query(), $document, $oldFilePath, $oldNomorSk, $oldCategory, 'sk_pangkat')->update($updates);
        $this->matchingHistoryRecords(PositionHistory::query(), $document, $oldFilePath, $oldNomorSk, $oldCategory, 'sk_jabatan')->update($updates);
        $this->matchingHistoryRecords(SalaryHistory::query(), $document, $oldFilePath, $oldNomorSk, $oldCategory, 'sk_kgb')->update($updates);
        $this->matchingHistoryRecords(DisciplineRecord::query(), $document, $oldFilePath, $oldNomorSk, $oldCategory, 'sk_hukuman_disiplin')->update($updates);
        $this->matchingHistoryRecords(Appointment::query(), $document, $oldFilePath, $oldNomorSk, $oldCategory, 'sk_pengangkatan')->update($updates);

        $this->matchingHistoryRecords(EmployeeStatusHistory::query(), $document, $oldFilePath, $oldNomorSk, $oldCategory, 'sk_status_pegawai', 'nomor_berkas')->update([
            'nomor_berkas' => $document->nomor_dokumen,
            'file_sk' => $document->file_path,
        ]);

        Employee::query()
            ->where('id', $document->employee_id)
            ->where(function (Builder $query) use ($oldFilePath, $oldNomorSk, $oldCategory): void {
                $query->where('status_berkas_path', $oldFilePath);

                if ($oldCategory === 'sk_status_pegawai' && filled($oldNomorSk)) {
                    $query->orWhere('status_nomor_berkas', $oldNomorSk);
                }
            })
            ->update([
                'status_nomor_berkas' => $document->nomor_dokumen,
                'status_berkas_path' => $document->file_path,
            ]);
    }

    /**
     * Cari riwayat dari path file terlebih dahulu; nomor SK hanya fallback
     * untuk kategori lama yang sesuai agar SK dengan nomor sama tidak tertaut keliru.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function matchingHistoryRecords(
        Builder $query,
        Document $document,
        string $oldFilePath,
        ?string $oldNomorSk,
        string $oldCategory,
        string $expectedCategory,
        string $numberColumn = 'no_sk',
    ): Builder {
        return $query
            ->where('employee_id', $document->employee_id)
            ->where(function (Builder $query) use ($oldFilePath, $oldNomorSk, $oldCategory, $expectedCategory, $numberColumn): void {
                $query->where('file_sk', $oldFilePath);

                if ($oldCategory === $expectedCategory && filled($oldNomorSk)) {
                    $query->orWhere($numberColumn, $oldNomorSk);
                }
            });
    }

    private function fileIsStillReferenced(string $filePath): bool
    {
        return Document::query()->where('file_path', $filePath)->exists()
            || Employee::query()->where('status_berkas_path', $filePath)->exists()
            || EmployeeStatusHistory::query()->where('file_sk', $filePath)->exists()
            || RankHistory::query()->where('file_sk', $filePath)->exists()
            || PositionHistory::query()->where('file_sk', $filePath)->exists()
            || SalaryHistory::query()->where('file_sk', $filePath)->exists()
            || DisciplineRecord::query()->where('file_sk', $filePath)->exists()
            || Appointment::query()->where('file_sk', $filePath)->exists();
    }
}
