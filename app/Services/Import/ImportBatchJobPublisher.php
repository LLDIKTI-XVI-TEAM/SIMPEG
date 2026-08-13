<?php

namespace App\Services\Import;

use App\Jobs\ImportEmployeeBatchJob;
use App\Models\ImportBatch;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Memublikasikan job import dari claim durable dengan lease dan marker keberhasilan broker. */
class ImportBatchJobPublisher
{
    private const PUBLISH_LEASE_SECONDS = 300;

    public function __construct(
        private readonly QueueFactory $queues,
        private readonly CacheRepository $cache,
    ) {}

    /** Menunda seluruh proses publish sampai transaksi caller terluar benar-benar commit. */
    public function dispatchAfterCommit(
        string $batchId,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        DB::afterCommit(function () use ($batchId, $ipAddress, $userAgent): void {
            $this->publish($batchId, $ipAddress, $userAgent);
        });
    }

    /**
     * Mengambil lease durable, memegang unique lock sekali, lalu menandai publish hanya setelah queue push berhasil.
     * Direct queue push dipakai setelah manual UniqueLock agar lock tidak di-acquire dua kali oleh PendingDispatch;
     * worker tetap melepas lock dari payload ShouldBeUnique pada lifecycle normalnya.
     */
    public function publish(
        string $batchId,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): bool {
        try {
            $batch = $this->claimPublishLease($batchId);
        } catch (\Throwable $exception) {
            $this->logFailure('Claim lease publish job import gagal.', $batchId, $exception);

            return false;
        }

        if ($batch === null || $batch->processing_token === null) {
            return false;
        }

        $job = new ImportEmployeeBatchJob(
            $batch->id,
            $batch->user_id,
            $ipAddress,
            $userAgent,
            $batch->processing_token,
        );
        $uniqueLock = new UniqueLock($this->cache);

        if (! $uniqueLock->acquire($job)) {
            return false;
        }

        try {
            $this->queues->connection($job->connection)->push($job, '', $job->queue);
        } catch (\Throwable $exception) {
            // Broker menolak publish: lock harus segera dilepas agar reconciler dapat mencoba ulang.
            $uniqueLock->release($job);
            $this->expirePublishLease($batch);
            $this->logFailure('Publish job import ke queue gagal.', $batch->id, $exception);

            return false;
        }

        try {
            ImportBatch::query()
                ->whereKey($batch->id)
                ->where('processing_token', $batch->processing_token)
                ->whereNull('job_published_at')
                ->update([
                    'job_published_at' => now(),
                    'job_publish_lease_expires_at' => null,
                ]);
        } catch (\Throwable $exception) {
            // Job sudah diterima queue; jangan lepas unique lock karena worker masih memiliki pesan yang sah.
            $this->logFailure('Marker publish job import gagal disimpan.', $batch->id, $exception);
        }

        return true;
    }

    /** Mengklaim satu attempt publish secara CAS agar reconciler paralel tidak memublikasikan row yang sama. */
    private function claimPublishLease(string $batchId): ?ImportBatch
    {
        return DB::transaction(function () use ($batchId): ?ImportBatch {
            $now = now();
            $updated = ImportBatch::query()
                ->whereKey($batchId)
                ->where('status', 'queued')
                ->whereNull('started_at')
                ->whereNull('job_published_at')
                ->whereNotNull('processing_token')
                ->where(function ($query) use ($now): void {
                    $query->whereNull('job_publish_lease_expires_at')
                        ->orWhere('job_publish_lease_expires_at', '<=', $now);
                })
                ->update([
                    'job_publish_attempted_at' => $now,
                    'job_publish_lease_expires_at' => $now->copy()->addSeconds(self::PUBLISH_LEASE_SECONDS),
                    'job_publish_attempts' => DB::raw('job_publish_attempts + 1'),
                ]);

            return $updated === 1 ? ImportBatch::query()->findOrFail($batchId) : null;
        });
    }

    /** Membuat attempt gagal segera eligible untuk retry tanpa menghapus claim batch. */
    private function expirePublishLease(ImportBatch $batch): void
    {
        try {
            ImportBatch::query()
                ->whereKey($batch->id)
                ->where('processing_token', $batch->processing_token)
                ->whereNull('job_published_at')
                ->update(['job_publish_lease_expires_at' => now()]);
        } catch (\Throwable $exception) {
            $this->logFailure('Lease publish job import gagal diakhiri.', $batch->id, $exception);
        }
    }

    /** Log operasional hanya menyimpan identitas batch dan kelas exception, tanpa payload atau pesan broker. */
    private function logFailure(string $message, string $batchId, \Throwable $exception): void
    {
        Log::warning($message, [
            'batch_id' => $batchId,
            'exception_class' => $exception::class,
        ]);
    }
}
