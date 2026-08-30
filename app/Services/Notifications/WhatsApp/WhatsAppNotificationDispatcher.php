<?php

namespace App\Services\Notifications\WhatsApp;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\WhatsAppNotificationDelivery;
use App\Models\WhatsAppNotificationOutbox;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;

class WhatsAppNotificationDispatcher
{
    public function __construct(
        private readonly NotificationEventCatalog $catalog,
        private readonly NotificationChannelResolver $channels,
        private readonly WhatsAppReadiness $readiness,
        private readonly WhatsAppRecipientResolver $recipientResolver,
        private readonly WhatsAppTemplatePayloadMapper $mapper,
        private readonly WhatsAppNotificationJobPublisher $publisher,
        private readonly WhatsAppRuntimeConfig $runtime,
    ) {}

    /**
     * Mengevaluasi gerbang fail-closed sebelum mencatat delivery audit dan mengantrekan job.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function dispatch(Employee $employee, string $eventKey, ?array $data = null): ?WhatsAppNotificationDelivery
    {
        $data ??= [];

        // Gerbang 1: Allowlist katalog event (in-memory)
        if (! $this->catalog->supportsChannel($eventKey, 'whatsapp_business')) {
            return null;
        }

        // Gerbang 2: Adapter runtime harus benar-benar tersedia. Konfigurasi lengkap
        // tidak boleh membuat outbox ketika aplikasi masih di mode candidate/nonaktif.
        if (! $this->catalog->hasAdapter('whatsapp_business')) {
            return null;
        }

        // Gerbang 3: Kesiapan konfigurasi provider resmi (in-memory fail-closed)
        if (! $this->readiness->isReady()) {
            return null;
        }

        // Gerbang 4: Kebijakan aktif channel per event di database
        if (! $this->channels->isEnabledForEvent($eventKey, 'whatsapp_business')) {
            return null;
        }

        // Gerbang 5: Resolusi alamat nomor WhatsApp kanonis terverifikasi
        $recipientAddress = $this->recipientResolver->resolve($employee);
        if ($recipientAddress === null || trim($recipientAddress) === '') {
            return null;
        }

        // Gerbang 6: Pemetaan template dan validasi privacy guard
        $mapped = $this->mapper->map($eventKey, $employee, $data);
        if ($mapped === null) {
            return null;
        }

        // Satu pengajuan dapat kembali aktif setelah rollover tanpa membuat request atau
        // snapshot approver baru. Payload siklus yang dibentuk Action menjadi identitas
        // immutable delivery untuk resubmit tersebut; event lama tetap terdeduplikasi.
        // Untuk event rollover return, sertakan tahun rollover agar siklus tahunan baru dapat terkirim.
        $contextKey = match ($eventKey) {
            'cuti.dikembalikan_karena_rollover' => isset($data['source_year'])
                ? (isset($data['leave_request_id']) ? "{$data['leave_request_id']}:{$data['source_year']}" : (string) $data['source_year'])
                : ($data['leave_request_id'] ?? null),
            'cuti.pengajuan_baru', 'cuti.menunggu_persetujuan' => isset($data['leave_request_step_id'])
                ? (string) $data['leave_request_step_id'].':'.($data['notification_cycle_id'] ?? 'initial')
                : ($data['notification_cycle_id'] ?? null),
            default => $data['notification_cycle_id']
                ?? $data['leave_approval_id']
                ?? $data['leave_request_id']
                ?? $data['ews_alert_id']
                ?? null,
        };

        $idempotencyKey = hash('sha256', "{$eventKey}:{$contextKey}:{$employee->id}");

        $shouldPublish = false;
        $outbox = null;

        $existing = WhatsAppNotificationDelivery::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            $delivery = $existing;
            $requeued = $this->requeueRecoverableEwsDelivery($delivery, $employee, $eventKey, $mapped, $data);
            if ($requeued !== null) {
                [$delivery, $outbox] = $requeued;
                $shouldPublish = true;
            }
        } else {
            try {
                // DB::transaction mengelola savepoint pada PostgreSQL; bila create gagal karena
                // collision concurrent, error di-catch di luar blok transaksi sehingga koneksi tetap bersih.
                /** @var array{0: WhatsAppNotificationDelivery, 1: WhatsAppNotificationOutbox} $created */
                $created = DB::transaction(function () use ($idempotencyKey, $employee, $eventKey, $mapped, $data): array {
                    $delivery = WhatsAppNotificationDelivery::create([
                        'idempotency_key' => $idempotencyKey,
                        'employee_id' => $employee->id,
                        'event_key' => $eventKey,
                        'template_key' => $mapped->templateKey,
                        'status' => WhatsAppNotificationDelivery::STATUS_QUEUED,
                        'attempt_count' => 0,
                    ]);

                    $outbox = WhatsAppNotificationOutbox::create([
                        'delivery_id' => $delivery->id,
                        'encrypted_payload' => $this->encryptJobPayload($idempotencyKey, $employee, $eventKey, $mapped, $data),
                    ]);

                    return [$delivery, $outbox];
                });
                [$delivery, $outbox] = $created;
                $shouldPublish = true;
            } catch (QueryException) {
                // Collision race condition: request paralel telah meng-insert record terlebih dahulu.
                // Savepoint gagal telah di-rollback, query ulang aman dieksekusi di PostgreSQL.
                $delivery = WhatsAppNotificationDelivery::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }
        }

        if ($shouldPublish && $outbox !== null) {
            $this->publisher->dispatchAfterCommit($outbox->id);
        }

        return $delivery;
    }

    /**
     * Hanya reminder EWS aktif yang sebelumnya diskip karena kill-switch sementara
     * dapat dibuka kembali. Skip domain (acknowledged, handled, owner hilang, dst.)
     * tetap terminal dan tidak boleh dihidupkan ulang oleh scheduler.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: WhatsAppNotificationDelivery, 1: WhatsAppNotificationOutbox}|null
     */
    private function requeueRecoverableEwsDelivery(
        WhatsAppNotificationDelivery $delivery,
        Employee $employee,
        string $eventKey,
        WhatsAppMappedTemplate $mapped,
        array $data,
    ): ?array {
        if (! str_starts_with($eventKey, 'ews.')
            || $delivery->status !== WhatsAppNotificationDelivery::STATUS_SKIPPED
            || $delivery->failure_code !== 'readiness_or_policy_disabled') {
            return null;
        }

        $alertId = $data['ews_alert_id'] ?? null;
        if (! is_string($alertId) || $alertId === '') {
            return null;
        }

        return DB::transaction(function () use ($delivery, $employee, $eventKey, $mapped, $data, $alertId): ?array {
            $lockedDelivery = WhatsAppNotificationDelivery::query()
                ->whereKey($delivery->id)
                ->lockForUpdate()
                ->first();
            if ($lockedDelivery === null
                || $lockedDelivery->status !== WhatsAppNotificationDelivery::STATUS_SKIPPED
                || $lockedDelivery->failure_code !== 'readiness_or_policy_disabled') {
                return null;
            }

            $alertIsStillActive = EwsAlert::query()
                ->whereKey($alertId)
                ->where('followup_status', EwsAlert::FOLLOWUP_STATUS_ACTIVE)
                ->whereNull('notification_acknowledged_at')
                ->exists();
            if (! $alertIsStillActive) {
                return null;
            }

            $lockedDelivery->update([
                'template_key' => $mapped->templateKey,
                'status' => WhatsAppNotificationDelivery::STATUS_QUEUED,
                'failure_code' => null,
                'lease_expires_at' => null,
            ]);

            $encryptedPayload = $this->encryptJobPayload(
                $lockedDelivery->idempotency_key,
                $employee,
                $eventKey,
                $mapped,
                $data,
            );
            $outbox = WhatsAppNotificationOutbox::query()
                ->where('delivery_id', $lockedDelivery->id)
                ->lockForUpdate()
                ->first();
            if ($outbox === null) {
                $outbox = WhatsAppNotificationOutbox::create([
                    'delivery_id' => $lockedDelivery->id,
                    'encrypted_payload' => $encryptedPayload,
                ]);
            } else {
                $outbox->update([
                    'encrypted_payload' => $encryptedPayload,
                    'published_at' => null,
                    // Requeue EWS karena kill-switch membentuk siklus publish baru.
                    // Batas percobaan broker tetap berlaku per siklus, bukan sepanjang
                    // umur reminder yang masih aktif dan belum diakui.
                    'publish_attempts' => 0,
                    'publish_attempted_at' => null,
                    'publish_lease_expires_at' => null,
                    'publish_failed_at' => null,
                    'publish_failure_code' => null,
                ]);
            }

            return [$lockedDelivery->fresh(), $outbox->fresh()];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    private function encryptJobPayload(
        string $idempotencyKey,
        Employee $employee,
        string $eventKey,
        WhatsAppMappedTemplate $mapped,
        array $data,
    ): string {
        $templateConfig = $this->runtime->template($mapped->templateKey);
        $variablesMap = is_array($templateConfig) ? ($templateConfig['variables_map'] ?? null) : null;
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'employee_id' => $employee->id,
            'event_key' => $eventKey,
            'template_key' => $mapped->templateKey,
            'template_id' => $mapped->templateId,
            'language' => $mapped->language,
            'variables' => $mapped->variables,
            'body_variables' => $mapped->bodyVariables,
            'button_variables' => $mapped->buttonVariables,
            'leave_request_id' => $data['leave_request_id'] ?? null,
            'leave_request_step_id' => $data['leave_request_step_id'] ?? null,
            'leave_request_version' => $data['leave_request_version'] ?? null,
            'leave_approval_id' => $data['leave_approval_id'] ?? null,
            'ews_alert_id' => $data['ews_alert_id'] ?? null,
            'variables_map' => is_array($variablesMap) ? $variablesMap : null,
            'leave_request_ids' => isset($data['leave_request_ids']) && is_array($data['leave_request_ids'])
                ? array_values(array_filter($data['leave_request_ids'], static fn (mixed $id): bool => is_string($id) && $id !== ''))
                : [],
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
