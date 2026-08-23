<?php

namespace App\Jobs;

use App\Models\Document;
use App\Support\Documents\BerkasLainnyaMutationGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class CleanupEmployeeDocumentFileJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Kegagalan I/O sementara dicoba ulang tanpa menahan request pengguna. */
    public int $tries = 3;

    /** Batasi operasi storage agar worker tidak tertahan terlalu lama. */
    public int $timeout = 30;

    public function __construct(public readonly string $filePath) {}

    /**
     * Bersihkan file privat yang metadata penghapusannya sudah commit.
     * Referensi selalu diperiksa ulang agar retry tidak menghapus file yang dipakai kembali.
     */
    public function handle(BerkasLainnyaMutationGuard $guard): void
    {
        if ($guard->fileIsStillReferenced($this->filePath)) {
            return;
        }

        if (! Storage::disk(Document::STORAGE_DISK)->delete($this->filePath)) {
            throw new RuntimeException('Pembersihan file dokumen pegawai gagal.');
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** Catat kegagalan final tanpa menyimpan path dokumen privat atau detail I/O mentah. */
    public function failed(Throwable $exception): void
    {
        Log::error('Pembersihan file dokumen pegawai gagal setelah retry maksimum.', [
            'file_path_hash' => hash('sha256', $this->filePath),
            'exception_class' => $exception::class,
        ]);
    }
}
