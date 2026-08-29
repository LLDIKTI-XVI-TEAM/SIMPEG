<?php

namespace App\Actions\Notifications;

use App\Models\WhatsAppNotificationOutbox;
use App\Services\Notifications\WhatsApp\WhatsAppNotificationJobPublisher;

/** Memulihkan outbox WhatsApp yang telah commit tetapi belum berhasil dipublish ke broker. */
class RecoverStaleWhatsAppDispatchesAction
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 100;

    private const STALE_MINUTES = 5;

    public function __construct(private readonly WhatsAppNotificationJobPublisher $publisher) {}

    /** @return array{scanned: int, published: int} */
    public function execute(int $limit = self::DEFAULT_LIMIT): array
    {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('Limit recovery WhatsApp harus antara 1 dan 100.');
        }

        $now = now();
        $staleBefore = $now->copy()->subMinutes(self::STALE_MINUTES);
        $outboxIds = WhatsAppNotificationOutbox::query()
            ->whereNull('published_at')
            ->whereNull('publish_failed_at')
            ->where('publish_attempts', '<', WhatsAppNotificationJobPublisher::MAX_PUBLISH_ATTEMPTS)
            ->where(function ($query) use ($now, $staleBefore): void {
                $query->where(function ($neverAttempted) use ($now, $staleBefore): void {
                    $neverAttempted->whereNull('publish_attempted_at')
                        ->where('created_at', '<=', $staleBefore)
                        ->where(function ($lease) use ($now): void {
                            $lease->whereNull('publish_lease_expires_at')
                                ->orWhere('publish_lease_expires_at', '<=', $now);
                        });
                })->orWhere(function ($retryable) use ($now): void {
                    $retryable->whereNotNull('publish_attempted_at')
                        ->where('publish_lease_expires_at', '<=', $now);
                });
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $published = 0;
        foreach ($outboxIds as $outboxId) {
            if ($this->publisher->publish((string) $outboxId)) {
                $published++;
            }
        }

        return ['scanned' => $outboxIds->count(), 'published' => $published];
    }
}
