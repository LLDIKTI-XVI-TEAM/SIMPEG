<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WhatsAppNotificationDelivery;
use App\Services\Ews\EwsEligibilityService;
use App\Services\LeaveApprovalService;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\WhatsApp\WhatsAppDeliveryResult;
use App\Services\Notifications\WhatsApp\WhatsAppReadiness;
use App\Services\Notifications\WhatsApp\WhatsAppRecipientResolver;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendWhatsAppNotificationJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_PROVIDER_ATTEMPTS = 3;

    /** @var list<string> */
    private const TERMINAL_FAILURE_CODES = [
        'delivery_rejected',
        'max_retries_exceeded',
        'network_timeout',
        'provider_misconfigured',
        'provider_response_ambiguous',
    ];

    public int $tries = self::MAX_PROVIDER_ATTEMPTS;

    public int $timeout = 30;

    public const LEASE_DURATION_SECONDS = 120;

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [self::LEASE_DURATION_SECONDS, self::LEASE_DURATION_SECONDS, self::LEASE_DURATION_SECONDS];
    }

    /**
     * @param  array<string, string>  $variables
     * @param  array<int|string, string>  $bodyVariables
     * @param  array<int|string, string>  $buttonVariables
     * @param  list<string>  $leaveRequestIds
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly string $employeeId,
        public readonly string $eventKey,
        public readonly string $templateKey,
        public readonly string $templateId,
        public readonly string $language,
        public readonly array $variables,
        public readonly array $bodyVariables = [],
        public readonly array $buttonVariables = [],
        public readonly ?string $leaveRequestId = null,
        public readonly ?string $leaveRequestStepId = null,
        public readonly ?string $leaveRequestVersion = null,
        public readonly ?string $leaveApprovalId = null,
        public readonly ?string $ewsAlertId = null,
        public readonly ?array $variablesMap = null,
        public readonly array $leaveRequestIds = [],
    ) {}

    /**
     * Mengeksekusi pengiriman pesan WhatsApp secara idempoten dan aman.
     */
    public function handle(
        WhatsAppReadiness $readiness,
        NotificationChannelResolver $channels,
        NotificationEventCatalog $catalog,
        WhatsAppRecipientResolver $recipientResolver,
        WhatsAppTemplateAdapter $adapter,
        WhatsAppRuntimeConfig $runtime,
    ): void {
        // Kebijakan channel dibaca tanpa cache; tidak ada alasan untuk mencoba ulang
        // ketika event memang tidak didukung atau operator menonaktifkan channel.
        if (! $catalog->supportsChannel($this->eventKey, 'whatsapp_business')
            || ! $channels->isEnabledForEvent($this->eventKey, 'whatsapp_business')) {
            $this->markSkipped('readiness_or_policy_disabled');

            return;
        }

        // Worker jangka panjang dapat masih menyimpan konfigurasi belum lengkap ketika
        // web process baru saja membuat job dari konfigurasi yang sudah lengkap. Refresh
        // sekali sebelum skip terminal agar delivery baru tidak hilang hanya karena TTL.
        if (! $readiness->isReady()) {
            $runtime->invalidate();

            if (! $readiness->isReady()) {
                $this->markSkipped('readiness_or_policy_disabled');

                return;
            }
        }

        // Mismatch kontrak bisa berasal dari memo setting yang basi pada worker
        // jangka panjang (operator baru mengganti/mengisi kontrak). Sebelum delivery
        // ditandai skip terminal, buang memo lalu baca ulang dari database sehingga
        // job berkontrak baru tidak dikorbankan oleh cache lama.
        if (! $this->queuedPayloadMatchesContract($runtime)) {
            $runtime->invalidate();

            if (! $this->queuedPayloadMatchesContract($runtime)) {
                $this->markSkipped('template_contract_invalid');

                return;
            }
        }

        // Resolusi ulang alamat nomor WhatsApp kanonis dari sumber terverifikasi saat runtime
        // Recipient yang sudah nonaktif tidak boleh menerima delivery tertunda dari outbox lama.
        $employee = Employee::query()->withActiveLifecycleStatus()->find($this->employeeId);
        if ($employee === null) {
            $this->markSkipped('recipient_not_found');

            return;
        }

        $recipientAddress = $recipientResolver->resolve($employee);
        if ($recipientAddress === null || trim($recipientAddress) === '') {
            $this->markSkipped('recipient_unverified');

            return;
        }

        // Untuk notifikasi tindakan cuti, validasi bahwa penerima masih merupakan approver aktif
        $isActionNotification = in_array(
            $this->eventKey,
            [
                'cuti.pengajuan_baru',
                'cuti.menunggu_persetujuan',
            ],
            true,
        );

        if (str_starts_with($this->eventKey, 'cuti.') && $this->leaveRequestId === null) {
            $this->markSkipped('leave_request_context_missing');

            return;
        }

        if ($isActionNotification) {
            if ($this->leaveRequestStepId === null || $this->leaveRequestVersion === null) {
                $this->markSkipped('approver_no_longer_active');

                return;
            }

            $leaveRequest = LeaveRequest::query()->find($this->leaveRequestId);
            if ($leaveRequest === null) {
                $this->markSkipped('approver_no_longer_active');

                return;
            }

            $currentVersion = $leaveRequest->updated_at?->utc()->format('Y-m-d\\TH:i:s.u\\Z');
            if ($currentVersion === null || ! hash_equals($this->leaveRequestVersion, $currentVersion)) {
                $this->markSkipped('leave_request_version_changed');

                return;
            }

            $activeStep = $leaveRequest->steps()
                ->whereKey($this->leaveRequestStepId)
                ->where('status', 'active')
                ->where('approver_employee_id', $this->employeeId)
                ->first();
            if ($activeStep === null
                || ! in_array($leaveRequest->status, LeaveApprovalService::ACTIONABLE_STATUSES, true)) {
                $this->markSkipped('approver_no_longer_active');

                return;
            }
        } else {
            $expectedStatus = match ($this->eventKey) {
                'cuti.disetujui' => 'disetujui',
                'cuti.ditunda' => 'ditangguhkan',
                'cuti.ditangguhkan_tugas_dinas' => 'ditangguhkan_tugas_dinas',
                'cuti.dikembalikan_karena_rollover' => 'dikembalikan_karena_rollover',
                'cuti.perlu_perubahan' => 'perlu_perubahan',
                'cuti.tidak_disetujui' => 'tidak_disetujui',
                default => null,
            };

            if ($expectedStatus !== null) {
                $leaveRequestIds = $this->eventKey === 'cuti.dikembalikan_karena_rollover'
                    ? array_values(array_unique($this->leaveRequestIds))
                    : [$this->leaveRequestId];

                if ($leaveRequestIds === []) {
                    $this->markSkipped('rollover_context_missing');

                    return;
                }

                $matchingRequests = LeaveRequest::query()
                    ->whereIn('id', $leaveRequestIds)
                    ->where('status', $expectedStatus)
                    ->count();

                if ($matchingRequests !== count($leaveRequestIds)) {
                    $this->markSkipped('status_changed_before_delivery');

                    return;
                }

                $expectedApprovalAction = match ($this->eventKey) {
                    'cuti.ditunda' => 'POSTPONE',
                    'cuti.ditangguhkan_tugas_dinas' => LeaveApproval::ACTION_DUTY_POSTPONEMENT,
                    'cuti.perlu_perubahan' => 'REQUEST_CHANGES',
                    'cuti.tidak_disetujui' => 'NOT_APPROVED',
                    default => null,
                };

                if ($expectedApprovalAction !== null) {
                    if ($this->leaveApprovalId === null || $this->leaveRequestId === null) {
                        $this->markSkipped('leave_approval_context_missing');

                        return;
                    }

                    $approval = LeaveApproval::query()
                        ->whereKey($this->leaveApprovalId)
                        ->where('leave_request_id', $this->leaveRequestId)
                        ->where('action', $expectedApprovalAction)
                        ->first();

                    $latestApprovalId = LeaveApproval::query()
                        ->where('leave_request_id', $this->leaveRequestId)
                        ->where('action', $expectedApprovalAction)
                        ->orderByDesc('acted_at')
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->value('id');

                    if ($approval === null || $latestApprovalId === null || ! hash_equals($this->leaveApprovalId, (string) $latestApprovalId)) {
                        $this->markSkipped('leave_approval_superseded');

                        return;
                    }
                }
            }
        }

        $currentEventTemplates = $runtime->eventTemplates();
        $currentTemplateKey = $currentEventTemplates[$this->eventKey] ?? null;

        if (! is_string($currentTemplateKey) || $currentTemplateKey !== $this->templateKey) {
            $this->markSkipped('template_mapping_changed');

            return;
        }

        $bodyVariables = $this->bodyVariables;

        if (str_starts_with($this->eventKey, 'ews.')) {
            if ($this->ewsAlertId === null) {
                $this->markSkipped('ews_context_missing');

                return;
            }

            $alert = EwsAlert::query()->find($this->ewsAlertId);
            if ($alert === null) {
                $this->markSkipped('ews_alert_not_found');

                return;
            }

            if ($alert->followup_status !== EwsAlert::FOLLOWUP_STATUS_ACTIVE) {
                $this->markSkipped('ews_alert_not_active');

                return;
            }

            if ($alert->notification_acknowledged_at !== null) {
                $this->markSkipped('ews_alert_acknowledged');

                return;
            }

            $expectedType = 'ews.'.strtolower($alert->type);
            if ($expectedType !== $this->eventKey) {
                $this->markSkipped('ews_alert_type_mismatch');

                return;
            }

            // Recipient dapat berupa Admin Kepegawaian, tetapi alert selalu milik
            // pegawai target. Owner wajib masih tersedia untuk seluruh tipe EWS;
            // jangan mengirim reminder target lama kepada Admin bila owner sudah nonaktif.
            $targetEmployee = Employee::query()
                ->withActiveLifecycleStatus()
                ->with('disciplineRecords')
                ->find($alert->employee_id);
            if ($targetEmployee === null) {
                $this->markSkipped('ews_target_not_found');

                return;
            }

            if ($this->eventKey === 'ews.kenaikan_pangkat') {
                $eligibility = app(EwsEligibilityService::class)->promotion($targetEmployee);
                if ($eligibility['is_eligible'] !== true) {
                    $this->markSkipped('ews_promotion_not_eligible');

                    return;
                }
            }

            if ($this->eventKey === 'ews.satyalancana') {
                $eligibility = app(EwsEligibilityService::class)->satyalancana($targetEmployee);
                if ($eligibility['is_eligible'] !== true) {
                    $this->markSkipped('ews_satyalancana_not_eligible');

                    return;
                }
            }

            if ($alert->employee_id !== $this->employeeId) {
                $isAdmin = User::query()
                    ->where('employee_id', $this->employeeId)
                    ->where('role', 'admin_kepegawaian')
                    ->whereIn('employee_id', Employee::query()->whereActiveStatus()->select('id'))
                    ->exists();

                if (! $isAdmin) {
                    $this->markSkipped('ews_recipient_no_longer_authorized');

                    return;
                }
            }

            $remainingTimeProviderKey = $this->variablesMap['sisa_waktu'] ?? null;
            if ($remainingTimeProviderKey !== null) {
                if (! is_string($remainingTimeProviderKey)
                    || ! array_key_exists($remainingTimeProviderKey, $bodyVariables)) {
                    $this->markSkipped('template_contract_invalid');

                    return;
                }

                // Antrean EWS dapat tertunda melewati pergantian hari. Hitung ulang nilai
                // relatif tepat sebelum kirim agar provider tidak menerima label yang basi.
                $remainingDays = (int) now()->startOfDay()->diffInDays($alert->target_date->copy()->startOfDay(), false);
                $bodyVariables[$remainingTimeProviderKey] = match (true) {
                    $remainingDays > 0 => "H-{$remainingDays} hari",
                    $remainingDays === 0 => 'Hari ini',
                    default => 'Lewat jatuh tempo',
                };
            }
        }

        // Kunci baris delivery untuk menjamin eksekusi atomik dan mencegah race condition antar-worker
        $delivery = DB::transaction(function (): ?WhatsAppNotificationDelivery {
            $record = WhatsAppNotificationDelivery::query()
                ->where('idempotency_key', $this->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                return WhatsAppNotificationDelivery::create([
                    'idempotency_key' => $this->idempotencyKey,
                    'employee_id' => $this->employeeId,
                    'event_key' => $this->eventKey,
                    'template_key' => $this->templateKey,
                    'status' => WhatsAppNotificationDelivery::STATUS_SENDING,
                    'attempt_count' => 1,
                    'lease_expires_at' => now()->addSeconds(self::LEASE_DURATION_SECONDS),
                ]);
            }

            // Jika status DELIVERED atau SKIPPED, batalkan (sudah final / idempoten)
            if ($record->status === WhatsAppNotificationDelivery::STATUS_DELIVERED
                || $record->status === WhatsAppNotificationDelivery::STATUS_SKIPPED
                || ($record->status === WhatsAppNotificationDelivery::STATUS_FAILED
                    && in_array($record->failure_code, self::TERMINAL_FAILURE_CODES, true))) {
                return null;
            }

            if ($record->status === WhatsAppNotificationDelivery::STATUS_SENDING) {
                // Lease aktif masih dimiliki worker lain; tunggu sampai hasilnya tersimpan.
                if ($record->lease_expires_at !== null && $record->lease_expires_at->isFuture()) {
                    $this->release(self::LEASE_DURATION_SECONDS);

                    return null;
                }

                // Setelah lease berakhir, tidak dapat dibuktikan apakah worker sebelumnya
                // mati sebelum atau sesudah POST. Tanpa idempotensi provider, jangan kirim ulang.
                $record->update([
                    'status' => WhatsAppNotificationDelivery::STATUS_FAILED,
                    'lease_expires_at' => null,
                    'failure_code' => 'provider_response_ambiguous',
                ]);

                return null;
            }

            // Outbox memakai at-least-once publish. Batas ini bersifat global pada
            // delivery, bukan hanya pada satu serialisasi job Laravel, agar job
            // duplikat tidak dapat memanggil provider melampaui budget yang disetujui.
            if ($record->attempt_count >= self::MAX_PROVIDER_ATTEMPTS) {
                $record->update([
                    'status' => WhatsAppNotificationDelivery::STATUS_FAILED,
                    'lease_expires_at' => null,
                    'failure_code' => 'max_retries_exceeded',
                ]);

                return null;
            }

            // Klaim pengiriman: perpanjang lease dan naikkan attempt_count
            $record->update([
                'status' => WhatsAppNotificationDelivery::STATUS_SENDING,
                'attempt_count' => $record->attempt_count + 1,
                'lease_expires_at' => now()->addSeconds(self::LEASE_DURATION_SECONDS),
            ]);

            return $record;
        });

        if ($delivery === null) {
            return;
        }

        $message = new WhatsAppTemplateMessage(
            idempotencyKey: $this->idempotencyKey,
            eventKey: $this->eventKey,
            templateKey: $this->templateKey,
            templateId: $this->templateId,
            language: $this->language,
            recipientAddress: $recipientAddress,
            bodyVariables: $bodyVariables,
            buttonVariables: $this->buttonVariables,
            recipientName: (string) $employee->nama_lengkap,
            variablesMap: $this->variablesMap,
        );

        try {
            $result = $adapter->send($message);
        } catch (Throwable $exception) {
            Log::warning('Adapter WhatsApp melempar exception yang telah dimasking.', [
                'employee_id' => $this->employeeId,
                'event_key' => $this->eventKey,
                'template_key' => $this->templateKey,
                'exception' => $exception::class,
            ]);

            $delivery->update([
                'status' => WhatsAppNotificationDelivery::STATUS_FAILED,
                'lease_expires_at' => null,
                'failure_code' => 'delivery_failed',
            ]);

            throw new RuntimeException('Pengiriman notifikasi WhatsApp gagal (delivery_failed).');
        }

        if ($result->delivered) {
            $delivery->update([
                'status' => WhatsAppNotificationDelivery::STATUS_DELIVERED,
                'delivered_at' => now(),
                'lease_expires_at' => null,
                'failure_code' => null,
            ]);

            return;
        }

        $safeFailureCode = in_array($result->code, WhatsAppDeliveryResult::ALLOWED_FAILURE_CODES, true)
            ? $result->code
            : 'delivery_failed';

        $delivery->update([
            'status' => WhatsAppNotificationDelivery::STATUS_FAILED,
            'lease_expires_at' => null,
            'failure_code' => $safeFailureCode,
        ]);

        // Penolakan 4xx memerlukan koreksi payload/konfigurasi, sedangkan timeout setelah
        // POST dan respons 5xx tidak membuktikan pesan belum diterima. Ketiganya terminal.
        if (in_array($safeFailureCode, self::TERMINAL_FAILURE_CODES, true)) {
            return;
        }

        throw new RuntimeException("Pengiriman notifikasi WhatsApp gagal ({$safeFailureCode}).");
    }

    /**
     * Memeriksa payload job terhadap kontrak template efektif yang berlaku saat ini.
     */
    private function queuedPayloadMatchesContract(WhatsAppRuntimeConfig $runtime): bool
    {
        $currentEventTemplates = $runtime->eventTemplates();
        $currentTemplateKey = $currentEventTemplates[$this->eventKey] ?? null;
        if (! is_string($currentTemplateKey) || trim($currentTemplateKey) === '') {
            return false;
        }

        $currentArchetype = WhatsAppTemplateContract::eventTemplateArchetypes()[$this->eventKey] ?? null;
        if (! is_string($currentArchetype) || trim($currentArchetype) === '') {
            return false;
        }

        return WhatsAppTemplateContract::matchesQueuedPayload(
            $currentTemplateKey,
            $this->templateId,
            $this->language,
            $this->bodyVariables,
            $this->buttonVariables,
            $this->variablesMap,
            $runtime->template($currentTemplateKey),
            $runtime->canonicalUrl(),
            $currentArchetype,
        );
    }

    private function markSkipped(string $failureCode): void
    {
        DB::transaction(function () use ($failureCode): void {
            $delivery = WhatsAppNotificationDelivery::query()
                ->where('idempotency_key', $this->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($delivery === null || in_array($delivery->status, [
                WhatsAppNotificationDelivery::STATUS_DELIVERED,
                WhatsAppNotificationDelivery::STATUS_SKIPPED,
            ], true) || ($delivery->status === WhatsAppNotificationDelivery::STATUS_FAILED
                && in_array($delivery->failure_code, self::TERMINAL_FAILURE_CODES, true))) {
                return;
            }

            $delivery->update([
                'status' => WhatsAppNotificationDelivery::STATUS_SKIPPED,
                'lease_expires_at' => null,
                'failure_code' => $failureCode,
            ]);
        });
    }

    /**
     * Mencatat kegagalan final tanpa membuka nomor telepon, isi template, atau exception sensitif.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Pengiriman notifikasi WhatsApp SIMPEG gagal setelah percobaan maksimum.', [
            'employee_id' => $this->employeeId,
            'event_key' => $this->eventKey,
            'template_key' => $this->templateKey,
            'exception' => $exception::class,
        ]);

        WhatsAppNotificationDelivery::query()
            ->where('idempotency_key', $this->idempotencyKey)
            ->whereNotIn('status', [
                WhatsAppNotificationDelivery::STATUS_DELIVERED,
                WhatsAppNotificationDelivery::STATUS_SKIPPED,
            ])
            ->update([
                'status' => WhatsAppNotificationDelivery::STATUS_FAILED,
                'lease_expires_at' => null,
                'failure_code' => 'max_retries_exceeded',
            ]);
    }
}
