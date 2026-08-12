<?php

namespace App\Actions\Employees;

use App\Models\ImportBatch;
use App\Services\Import\ImportBatchJobPublisher;

/** Memulihkan publish job import yang kehilangan callback setelah claim durable commit. */
class RecoverStaleImportBatchDispatchesAction
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    private const STALE_MINUTES = 5;

    public function __construct(private readonly ImportBatchJobPublisher $publisher) {}

    /**
     * Memproses daftar id yang dibatasi; publisher mengambil CAS lease per row agar run paralel tetap aman.
     *
     * @return array{scanned: int, published: int}
     */
    public function execute(int $limit = self::DEFAULT_LIMIT): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Limit recovery import harus antara 1 dan 100.');
        }

        $now = now();
        $staleBefore = $now->copy()->subMinutes(self::STALE_MINUTES);
        $batchIds = ImportBatch::query()
            ->where('status', 'queued')
            ->whereNull('started_at')
            ->whereNull('job_published_at')
            ->whereNotNull('processing_token')
            ->where(function ($query) use ($now, $staleBefore): void {
                $query->where(function ($neverAttempted) use ($staleBefore): void {
                    $neverAttempted->whereNull('job_publish_attempted_at')
                        ->where('created_at', '<=', $staleBefore);
                })->orWhere('job_publish_lease_expires_at', '<=', $now);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $published = 0;
        foreach ($batchIds as $batchId) {
            if ($this->publisher->publish((string) $batchId)) {
                $published++;
            }
        }

        return ['scanned' => $batchIds->count(), 'published' => $published];
    }
}
