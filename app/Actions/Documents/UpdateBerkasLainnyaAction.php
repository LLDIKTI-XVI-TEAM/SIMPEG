<?php

namespace App\Actions\Documents;

use App\Actions\Documents\Concerns\BuildsDocumentAuditPayload;
use App\Models\Document;
use App\Models\Employee;
use App\Services\AuditService;
use App\Services\TransactionSideEffectManager;
use App\Support\Documents\BerkasLainnyaMutationGuard;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class UpdateBerkasLainnyaAction
{
    use BuildsDocumentAuditPayload;

    public function __construct(
        private readonly TransactionSideEffectManager $sideEffects,
        private readonly BerkasLainnyaMutationGuard $guard,
    ) {}

    /**
     * Memperbarui metadata atau file Berkas Lainnya secara atomik.
     *
     * File lama dipertahankan sampai transaksi terluar commit, sedangkan file baru
     * dibersihkan bila audit atau transaksi request gagal.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(
        Employee $employee,
        Document $document,
        array $data,
        ?UploadedFile $file = null,
        ?Request $request = null,
    ): Document {
        $this->guard->assertCanMutate($employee, $document);

        $disk = Storage::disk(Document::STORAGE_DISK);
        $replacementPath = null;

        if ($file !== null) {
            $category = (string) $data['kategori_dokumen'];
            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $filename = $employee->id.'_'.$category.'_'.Str::uuid().'.'.$extension;
            $replacementPath = $file->storeAs($employee->id.'/'.$category, $filename, Document::STORAGE_DISK);
            $pathForRollback = $replacementPath;

            $this->sideEffects->afterRollback(function () use ($disk, $pathForRollback): void {
                $disk->delete($pathForRollback);
            });
        }

        try {
            [$updatedDocument, $oldFilePath] = DB::transaction(function () use (
                $employee,
                $document,
                $data,
                $replacementPath,
                $request,
            ): array {
                /** @var Document $lockedDocument */
                $lockedDocument = Document::query()
                    ->whereKey($document->id)
                    ->where('employee_id', $employee->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Ulangi guard setelah lock agar perubahan tidak bergantung pada state sebelum transaksi.
                $this->guard->assertCanMutate($employee, $lockedDocument);
                $before = $this->auditPayload($lockedDocument);
                $oldFilePath = $lockedDocument->file_path;

                $lockedDocument->update([
                    'jenis_dokumen' => $data['kategori_dokumen'],
                    'nama_dokumen' => $data['nama_dokumen'],
                    'nomor_dokumen' => $data['nomor_dokumen'] ?? null,
                    'tanggal_dokumen' => $data['tanggal_terbit'] ?? null,
                    'file_path' => $replacementPath ?? $oldFilePath,
                    'keterangan' => $data['keterangan'] ?? null,
                ]);

                AuditService::logOrFail(
                    'UPDATE',
                    'Document',
                    $lockedDocument->id,
                    $before,
                    $this->auditPayload($lockedDocument->refresh()),
                    $request,
                );

                return [$lockedDocument->refresh(), $oldFilePath];
            });
        } catch (Throwable $exception) {
            if ($replacementPath !== null) {
                $disk->delete($replacementPath);
            }

            throw $exception;
        }

        if ($replacementPath !== null && $oldFilePath !== $replacementPath) {
            $deleteOldFile = function () use ($disk, $oldFilePath): void {
                if (! $this->guard->fileIsStillReferenced($oldFilePath)) {
                    $disk->delete($oldFilePath);
                }
            };

            if (! $this->sideEffects->afterCommit($deleteOldFile)) {
                $deleteOldFile();
            }
        }

        return $updatedDocument;
    }
}
