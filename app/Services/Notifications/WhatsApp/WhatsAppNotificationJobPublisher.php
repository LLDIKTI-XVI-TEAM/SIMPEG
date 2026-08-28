<?php

namespace App\Services\Notifications\WhatsApp;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\WhatsAppNotificationDelivery;
use App\Models\WhatsAppNotificationOutbox;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

/** Mempublikasikan job WhatsApp dari outbox durable setelah transaksi domain commit. */
class WhatsAppNotificationJobPublisher
{
    public const MAX_PUBLISH_ATTEMPTS = 3;

    private const PUBLISH_LEASE_SECONDS = 300;

    public function __construct(private readonly QueueFactory $queues) {}

    public function dispatchAfterCommit(string $outboxId): void
    {
        DB::afterCommit(fn (): bool => $this->publish($outboxId));
    }

    /**
     * Memegang lease dengan CAS sebelum push. Marker published hanya ditulis setelah
     * broker menerima job; kegagalan broker membiarkan outbox layak dipulihkan.
     */
    public function publish(string $outboxId): bool
    {
        try {
            $outbox = $this->claimPublishLease($outboxId);
        } catch (\Throwable $exception) {
            $this->logFailure('Claim publish outbox WhatsApp gagal.', $outboxId, $exception);

            return false;
        }

        if ($outbox === null) {
            return false;
        }

        try {
            $payload = json_decode(Crypt::decryptString($outbox->encrypted_payload), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new JsonException('Payload outbox bukan objek JSON.');
            }

            $job = new SendWhatsAppNotificationJob(
                idempotencyKey: (string) ($payload['idempotency_key'] ?? ''),
                employeeId: (string) ($payload['employee_id'] ?? ''),
                eventKey: (string) ($payload['event_key'] ?? ''),
                templateKey: (string) ($payload['template_key'] ?? ''),
                templateId: (string) ($payload['template_id'] ?? ''),
                language: (string) ($payload['language'] ?? ''),
                variables: is_array($payload['variables'] ?? null) ? $payload['variables'] : [],
                bodyVariables: is_array($payload['body_variables'] ?? null) ? $payload['body_variables'] : [],
                buttonVariables: is_array($payload['button_variables'] ?? null) ? $payload['button_variables'] : [],
                leaveRequestId: is_string($payload['leave_request_id'] ?? null) ? $payload['leave_request_id'] : null,
                leaveRequestStepId: is_string($payload['leave_request_step_id'] ?? null) ? $payload['leave_request_step_id'] : null,
                leaveRequestVersion: is_string($payload['leave_request_version'] ?? null) ? $payload['leave_request_version'] : null,
                leaveApprovalId: is_string($payload['leave_approval_id'] ?? null) ? $payload['leave_approval_id'] : null,
                ewsAlertId: is_string($payload['ews_alert_id'] ?? null) ? $payload['ews_alert_id'] : null,
                variablesMap: is_array($payload['variables_map'] ?? null) ? $payload['variables_map'] : null,
                leaveRequestIds: is_array($payload['leave_request_ids'] ?? null) ? $payload['leave_request_ids'] : [],
            );

            if ($job->idempotencyKey === '' || $job->employeeId === '' || $job->eventKey === '' || $job->templateKey === '' || $job->templateId === '' || $job->language === '') {
                throw new JsonException('Payload outbox tidak lengkap.');
            }

            $this->queues->connection($job->connection)->push($job, '', $job->queue);
        } catch (\Throwable $exception) {
            $this->failOrExpirePublishLease($outbox);
            $this->logFailure('Publish job WhatsApp ke queue gagal.', $outbox->id, $exception);

            return false;
        }

        try {
            WhatsAppNotificationOutbox::query()
                ->whereKey($outbox->id)
                ->whereNull('published_at')
                ->whereNull('publish_failed_at')
                ->update([
                    'published_at' => now(),
                    'publish_lease_expires_at' => null,
                ]);
        } catch (\Throwable $exception) {
            // Broker sudah menerima job; jangan mencoba lagi secara paksa. Job delivery
            // sendiri idempoten bila reconciler kemudian mempublikasikan duplikasi.
            $this->logFailure('Marker publish outbox WhatsApp gagal disimpan.', $outbox->id, $exception);
        }

        return true;
    }

    private function claimPublishLease(string $outboxId): ?WhatsAppNotificationOutbox
    {
        return DB::transaction(function () use ($outboxId): ?WhatsAppNotificationOutbox {
            $now = now();
            $updated = WhatsAppNotificationOutbox::query()
                ->whereKey($outboxId)
                ->whereNull('published_at')
                ->whereNull('publish_failed_at')
                ->where('publish_attempts', '<', self::MAX_PUBLISH_ATTEMPTS)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('publish_lease_expires_at')
                        ->orWhere('publish_lease_expires_at', '<=', $now);
                })
                ->update([
                    'publish_attempted_at' => $now,
                    'publish_lease_expires_at' => $now->copy()->addSeconds(self::PUBLISH_LEASE_SECONDS),
                    'publish_attempts' => DB::raw('publish_attempts + 1'),
                ]);

            return $updated === 1 ? WhatsAppNotificationOutbox::query()->findOrFail($outboxId) : null;
        });
    }

    private function failOrExpirePublishLease(WhatsAppNotificationOutbox $outbox): void
    {
        DB::transaction(function () use ($outbox): void {
            $now = now();
            $markedTerminal = WhatsAppNotificationOutbox::query()
                ->whereKey($outbox->id)
                ->whereNull('published_at')
                ->whereNull('publish_failed_at')
                ->where('publish_attempts', '>=', self::MAX_PUBLISH_ATTEMPTS)
                ->update([
                    'publish_lease_expires_at' => null,
                    'publish_failed_at' => $now,
                    'publish_failure_code' => 'publish_failed',
                ]);

            if ($markedTerminal === 1) {
                WhatsAppNotificationDelivery::query()
                    ->whereKey($outbox->delivery_id)
                    ->where('status', WhatsAppNotificationDelivery::STATUS_QUEUED)
                    ->update([
                        'status' => WhatsAppNotificationDelivery::STATUS_FAILED,
                        'failure_code' => 'publish_failed',
                        'lease_expires_at' => null,
                    ]);

                return;
            }

            WhatsAppNotificationOutbox::query()
                ->whereKey($outbox->id)
                ->whereNull('published_at')
                ->whereNull('publish_failed_at')
                ->update(['publish_lease_expires_at' => $now]);
        });
    }

    private function logFailure(string $message, string $outboxId, \Throwable $exception): void
    {
        Log::warning($message, [
            'outbox_id' => $outboxId,
            'exception_class' => $exception::class,
        ]);
    }
}
