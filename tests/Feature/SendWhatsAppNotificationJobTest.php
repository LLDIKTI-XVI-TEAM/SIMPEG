<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestStep;
use App\Models\NotificationEventChannel;
use App\Models\RefJenisCuti;
use App\Models\RefNotificationChannel;
use App\Models\RefStatusPegawai;
use App\Models\User;
use App\Models\WhatsAppNotificationDelivery;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\WhatsApp\WhatsAppDeliveryResult;
use App\Services\Notifications\WhatsApp\WhatsAppReadiness;
use App\Services\Notifications\WhatsApp\WhatsAppRecipientResolver;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateAdapter;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\FakeWhatsAppTemplateAdapter;
use Tests\TestCase;

class SendWhatsAppNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppTemplateAdapter $fakeAdapter;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
        ]);
        $this->fakeAdapter = new FakeWhatsAppTemplateAdapter;
        $this->app->instance(WhatsAppTemplateAdapter::class, $this->fakeAdapter);
    }

    private function enableWhatsAppForEvent(string $eventKey): void
    {
        config([
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.templates.simpeg_cuti_status' => [
                'id' => 'tmpl_cuti_status_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'status' => '3',
                    'keterangan' => '4',
                    'tautan_detail' => '5',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
            'services.whatsapp.templates.simpeg_cuti_status_lama' => [
                'id' => 'tmpl_cuti_status_lama',
                'language' => 'id',
                'archetype' => 'simpeg_cuti_status',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'status' => '3',
                    'keterangan' => '4',
                    'tautan_detail' => '5',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
            'services.whatsapp.templates.simpeg_ews_pengingat' => [
                'id' => 'tmpl_ews_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_peringatan' => '2',
                    'tanggal_target' => '3',
                    'sisa_waktu' => '4',
                    'tautan_detail' => '5',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
        ]);

        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channel->forceFill(['is_enabled' => true])->save();

        NotificationEventChannel::updateOrCreate(
            ['event_key' => $eventKey, 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        $mockReadiness = $this->createMock(WhatsAppReadiness::class);
        $mockReadiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $mockReadiness);

        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);
    }

    /** @return array<int|string, string> */
    private function providerBodyVariables(): array
    {
        return [
            '1' => 'Ahmad',
            '2' => 'Cuti Tahunan',
            '3' => 'Disetujui',
            '4' => 'Disetujui sesuai usulan.',
            '5' => 'https://simpeg.example.test/cuti/1',
        ];
    }

    /** @return array<string, string> */
    private function providerButtonVariables(): array
    {
        return ['button_target_url' => 'https://simpeg.example.test/cuti/1'];
    }

    /** @return array<string, string> */
    private function providerVariablesMap(string $templateKey): array
    {
        $variablesMap = config("services.whatsapp.templates.{$templateKey}.variables_map");

        $this->assertIsArray($variablesMap);

        return $variablesMap;
    }

    private function createLeaveRequest(Employee $employee, string $status = 'disetujui'): LeaveRequest
    {
        $jenisCuti = RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
        );

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-03',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Keperluan keluarga',
            'status' => $status,
        ]);
    }

    public function test_job_memiliki_konfigurasi_timeout_dan_retry_yang_sesuai(): void
    {
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'test-timeout',
            employeeId: 'emp-1',
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: [],
        );

        $this->assertSame(30, $job->timeout);
        $this->assertSame(3, $job->tries);
        $this->assertSame([120, 120, 120], $job->backoff());
    }

    public function test_job_berhasil_mengirim_dan_memperbarui_delivery_record(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-test-1',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-test-1',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad', 'status' => 'Disetujui'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
            leaveRequestId: $leaveRequest->id,
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertSentCount(1);
        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertNull($delivery->lease_expires_at);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNull($delivery->failure_code);
    }

    public function test_job_dilewati_bila_nomor_penerima_tidak_terverifikasi_saat_runtime(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();

        // Mock resolver mengembalikan null (misal nomor dicabut setelah dispatch)
        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn(null);
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-unverified',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-unverified',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            $mockResolver,
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertSame('recipient_unverified', $delivery->failure_code);
    }

    public function test_job_menolak_payload_tanpa_variabel_body_provider(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-template-contract-invalid',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad', 'status' => 'Disetujui'],
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('template_contract_invalid', $delivery->failure_code);
    }

    public function test_perubahan_canonical_url_saat_runtime_menggagalkan_validasi_tombol(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-canonical-changed',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        // URL tombol pada antrean memakai domain lama
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: ['button_target_url' => 'https://domain-lama.test/cuti/1'],
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('template_contract_invalid', $delivery->failure_code);
    }

    public function test_exception_adapter_dimasking_sebelum_masuk_ke_queue_worker(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $this->fakeAdapter->setNextException(new RuntimeException('RAW_PROVIDER_SECRET=rahasia'));

        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-adapter-throws',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad', 'status' => 'Disetujui'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
            leaveRequestId: $leaveRequest->id,
        );

        try {
            $job->handle(
                app(WhatsAppReadiness::class),
                app(NotificationChannelResolver::class),
                app(NotificationEventCatalog::class),
                app(WhatsAppRecipientResolver::class),
                $this->fakeAdapter,
            );
            $this->fail('Job harus melempar exception generik untuk memicu retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Pengiriman notifikasi WhatsApp gagal (delivery_failed).', $exception->getMessage());
            $this->assertStringNotContainsString('RAW_PROVIDER_SECRET', $exception->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('delivery_failed', $delivery->failure_code);
        $this->assertNull($delivery->lease_expires_at);
    }

    public function test_kill_switch_tidak_menimpa_delivery_yang_sudah_final(): void
    {
        $employee = Employee::factory()->create();
        $deliveredAt = now()->subMinute();
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-final-delivery',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => WhatsAppNotificationDelivery::STATUS_DELIVERED,
            'attempt_count' => 1,
            'delivered_at' => $deliveredAt,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_failed_callback_tidak_menimpa_delivery_yang_sudah_skipped(): void
    {
        $employee = Employee::factory()->create();
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-skipped-delivery',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => WhatsAppNotificationDelivery::STATUS_SKIPPED,
            'attempt_count' => 0,
            'failure_code' => 'readiness_or_policy_disabled',
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
        );

        $job->failed(new RuntimeException('RAW_PROVIDER_SECRET=rahasia'));

        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertSame('readiness_or_policy_disabled', $delivery->failure_code);
    }

    public function test_job_idempoten_tidak_mengirim_ulang_jika_status_sudah_delivered(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();

        WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-test-2',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => WhatsAppNotificationDelivery::STATUS_DELIVERED,
            'attempt_count' => 1,
            'delivered_at' => now(),
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-test-2',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
    }

    public function test_race_worker_tercegah_saat_lease_masih_aktif(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);

        // Simulasi worker 1 sedang memproses dengan lease aktif di masa depan
        WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-race-1',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => WhatsAppNotificationDelivery::STATUS_SENDING,
            'attempt_count' => 1,
            'lease_expires_at' => now()->addSeconds(60),
        ]);

        // Worker 2 mencoba memproses job yang sama
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-race-1',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
            leaveRequestId: $leaveRequest->id,
        );
        $queueJob = $this->createMock(QueueJob::class);
        $queueJob->expects($this->once())
            ->method('release')
            ->with(SendWhatsAppNotificationJob::LEASE_DURATION_SECONDS);
        $job->setJob($queueJob);

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        // Worker 2 tidak boleh mengirim ulang ke adapter
        $this->fakeAdapter->assertNotSent();
    }

    public function test_retry_dapat_mengklaim_ulang_jika_lease_sudah_kedaluwarsa(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);

        // Simulasi worker 1 mati/timeout sehingga lease_expires_at sudah lampau
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-lease-expired',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => WhatsAppNotificationDelivery::STATUS_SENDING,
            'attempt_count' => 1,
            'lease_expires_at' => now()->subMinutes(5),
        ]);

        // Worker retry mencoba memproses kembali
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-lease-expired',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad', 'status' => 'Disetujui'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
            leaveRequestId: $leaveRequest->id,
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        // Pengiriman berhasil dieksekusi oleh worker retry
        $this->fakeAdapter->assertSentCount(1);
        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_DELIVERED, $delivery->status);
        $this->assertSame(2, $delivery->attempt_count);
    }

    public function test_job_ditandai_skipped_jika_readiness_atau_kebijakan_nonaktif_saat_runtime(): void
    {
        // Readiness default false (tanpa mock)
        $employee = Employee::factory()->create();

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-test-3',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-test-3',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->status);
    }

    public function test_error_provider_tidak_dikenal_dimasking_menjadi_delivery_failed(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        // Simulasi error sensitif dari provider
        $this->fakeAdapter->setNextResult(WhatsAppDeliveryResult::failed('RAW_PROVIDER_SECRET_API_KEY_EXPIRED'));

        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-test-masking',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-test-masking',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
            leaveRequestId: $leaveRequest->id,
        );

        try {
            $job->handle(
                app(WhatsAppReadiness::class),
                app(NotificationChannelResolver::class),
                app(NotificationEventCatalog::class),
                app(WhatsAppRecipientResolver::class),
                $this->fakeAdapter,
            );
            $this->fail('Harus melempar RuntimeException saat delivery gagal');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('RAW_PROVIDER_SECRET', $e->getMessage());
            $this->assertSame('Pengiriman notifikasi WhatsApp gagal (delivery_failed).', $e->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('delivery_failed', $delivery->failure_code);
    }

    public function test_error_provider_terdaftar_disimpan_dengan_kode_aman(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $this->fakeAdapter->setNextResult(WhatsAppDeliveryResult::failed('network_timeout'));

        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-test-4',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-test-4',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
            leaveRequestId: $leaveRequest->id,
        );

        try {
            $job->handle(
                app(WhatsAppReadiness::class),
                app(NotificationChannelResolver::class),
                app(NotificationEventCatalog::class),
                app(WhatsAppRecipientResolver::class),
                $this->fakeAdapter,
            );
            $this->fail('Harus melempar RuntimeException saat delivery gagal');
        } catch (RuntimeException $e) {
            $this->assertSame('Pengiriman notifikasi WhatsApp gagal (network_timeout).', $e->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('network_timeout', $delivery->failure_code);
    }

    public function test_kegagalan_provider_unavailable_memicu_exception_untuk_retry(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $this->fakeAdapter->setNextResult(WhatsAppDeliveryResult::failed('provider_unavailable'));

        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-test-unavailable',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: 'idempotency-test-unavailable',
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
            leaveRequestId: $leaveRequest->id,
        );

        try {
            $job->handle(
                app(WhatsAppReadiness::class),
                app(NotificationChannelResolver::class),
                app(NotificationEventCatalog::class),
                app(WhatsAppRecipientResolver::class),
                $this->fakeAdapter,
            );
            $this->fail('Provider unavailable harus melempar RuntimeException untuk memicu retry antrean.');
        } catch (RuntimeException $e) {
            $this->assertSame('Pengiriman notifikasi WhatsApp gagal (provider_unavailable).', $e->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('provider_unavailable', $delivery->failure_code);
    }

    public function test_job_cuti_perlu_tindakan_dilewati_bila_approver_sudah_tidak_aktif_saat_runtime(): void
    {
        config([
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => [
                'id' => 'tmpl_cuti_perlu_tindakan_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'tanggal_mulai' => '3',
                    'tanggal_selesai' => '4',
                    'jumlah_hari' => '5',
                    'tautan_detail' => '6',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
        ]);

        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channel->forceFill(['is_enabled' => true])->save();

        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'cuti.pengajuan_baru', 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        $mockReadiness = $this->createMock(WhatsAppReadiness::class);
        $mockReadiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $mockReadiness);

        $applicant = Employee::factory()->create(['nama_lengkap' => 'Pemohon']);
        $approver1 = Employee::factory()->create(['nama_lengkap' => 'Approver 1']);
        $approver2 = Employee::factory()->create(['nama_lengkap' => 'Approver 2']);

        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);

        $jenisCuti = RefJenisCuti::firstOrCreate(['code' => 'tahunan'], ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-03',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Liburan',
            'status' => 'menunggu_approval',
        ]);

        // Step 1 sudah approved (approver 1 sudah bertindak in-app sebelum worker berjalan)
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 1,
            'step_type' => 'supervisor',
            'role_label' => 'Atasan Langsung',
            'approver_employee_id' => $approver1->id,
            'status' => 'approved',
            'acted_at' => now(),
        ]);

        // Step 2 kini menjadi active (approver 2 yang sekarang aktif)
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 2,
            'step_type' => 'head',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver2->id,
            'status' => 'active',
        ]);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-approver-outdated',
            'employee_id' => $approver1->id,
            'event_key' => 'cuti.pengajuan_baru',
            'template_key' => 'simpeg_cuti_perlu_tindakan',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        // Job untuk approver 1 terlambat dieksekusi oleh worker (URL menggunakan route daftar approval umum /cuti/approval)
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $approver1->id,
            eventKey: 'cuti.pengajuan_baru',
            templateKey: 'simpeg_cuti_perlu_tindakan',
            templateId: 'tmpl_cuti_perlu_tindakan_123',
            language: 'id',
            variables: [
                'nama_pegawai' => 'Pemohon',
                'jenis_cuti' => 'Cuti Tahunan',
                'tanggal_mulai' => '01 September 2026',
                'tanggal_selesai' => '03 September 2026',
                'jumlah_hari' => '3 hari kerja',
                'tautan_detail' => 'https://simpeg.example.test/cuti/approval',
            ],
            bodyVariables: [
                '1' => 'Pemohon',
                '2' => 'Cuti Tahunan',
                '3' => '01 September 2026',
                '4' => '03 September 2026',
                '5' => '3 hari kerja',
                '6' => 'https://simpeg.example.test/cuti/approval',
            ],
            buttonVariables: [
                'button_target_url' => 'https://simpeg.example.test/cuti/approval',
            ],
            leaveRequestId: $leaveRequest->id,
            variablesMap: $this->providerVariablesMap('simpeg_cuti_perlu_tindakan'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        // Job harus diskip dan tidak mengirim instruksi tindakan usang ke approver 1
        $this->fakeAdapter->assertNotSent();
        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertSame('approver_no_longer_active', $delivery->failure_code);
    }

    public function test_job_cuti_tindakan_dilewati_bila_approver_sudah_tidak_aktif_meski_menggunakan_split_template(): void
    {
        config([
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.event_templates' => [
                'cuti.pengajuan_baru' => 'provider_cuti_pengajuan_v1',
            ],
            'services.whatsapp.templates.provider_cuti_pengajuan_v1' => [
                'archetype' => 'simpeg_cuti_perlu_tindakan',
                'id' => 'tmpl_provider_cuti_pengajuan_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'tanggal_mulai' => '3',
                    'tanggal_selesai' => '4',
                    'jumlah_hari' => '5',
                    'tautan_detail' => '6',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
        ]);

        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channel->forceFill(['is_enabled' => true])->save();

        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'cuti.pengajuan_baru', 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        $mockReadiness = $this->createMock(WhatsAppReadiness::class);
        $mockReadiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $mockReadiness);

        $applicant = Employee::factory()->create(['nama_lengkap' => 'Pemohon']);
        $approver1 = Employee::factory()->create(['nama_lengkap' => 'Approver 1']);
        $approver2 = Employee::factory()->create(['nama_lengkap' => 'Approver 2']);

        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);

        $jenisCuti = RefJenisCuti::firstOrCreate(['code' => 'tahunan'], ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false]);
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $applicant->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-03',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Liburan',
            'status' => 'menunggu_approval',
        ]);

        // Step 1 sudah approved
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 1,
            'step_type' => 'supervisor',
            'role_label' => 'Atasan Langsung',
            'approver_employee_id' => $approver1->id,
            'status' => 'approved',
            'acted_at' => now(),
        ]);

        // Step 2 kini menjadi active (approver 2 yang sekarang aktif)
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 2,
            'step_type' => 'head',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver2->id,
            'status' => 'active',
        ]);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-approver-outdated-split',
            'employee_id' => $approver1->id,
            'event_key' => 'cuti.pengajuan_baru',
            'template_key' => 'provider_cuti_pengajuan_v1',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $approver1->id,
            eventKey: 'cuti.pengajuan_baru',
            templateKey: 'provider_cuti_pengajuan_v1',
            templateId: 'tmpl_provider_cuti_pengajuan_123',
            language: 'id',
            variables: [
                'nama_pegawai' => 'Pemohon',
                'jenis_cuti' => 'Cuti Tahunan',
                'tanggal_mulai' => '01 September 2026',
                'tanggal_selesai' => '03 September 2026',
                'jumlah_hari' => '3 hari kerja',
                'tautan_detail' => 'https://simpeg.example.test/cuti/approval',
            ],
            bodyVariables: [
                '1' => 'Pemohon',
                '2' => 'Cuti Tahunan',
                '3' => '01 September 2026',
                '4' => '03 September 2026',
                '5' => '3 hari kerja',
                '6' => 'https://simpeg.example.test/cuti/approval',
            ],
            buttonVariables: [
                'button_target_url' => 'https://simpeg.example.test/cuti/approval',
            ],
            leaveRequestId: $leaveRequest->id,
            variablesMap: $this->providerVariablesMap('provider_cuti_pengajuan_v1'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $delivery->refresh();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->status);
        $this->assertSame('approver_no_longer_active', $delivery->failure_code);
    }

    public function test_pekerjaan_dilewati_jika_kontrak_template_berubah_di_tengah_jalan(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-template-changed',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status_lama',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status_lama',
            templateId: 'tmpl_cuti_status_lama',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status_lama'),
            leaveRequestId: $leaveRequest->id,
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('template_contract_invalid', $delivery->failure_code);
    }

    private function providerEwsBodyVariables(): array
    {
        return [
            '1' => 'Ahmad',
            '2' => 'Kenaikan Pangkat',
            '3' => '01 Oktober 2026',
            '4' => 'H-30 hari',
            '5' => 'https://simpeg.example.test/ews-saya',
        ];
    }

    private function providerEwsButtonVariables(): array
    {
        return ['button_target_url' => 'https://simpeg.example.test/ews-saya'];
    }

    public function test_pekerjaan_ews_dilewati_jika_telah_diakui_pegawai(): void
    {
        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $employee = Employee::factory()->create();

        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            'notification_acknowledged_at' => now(),
        ]);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-ack',
            'employee_id' => $employee->id,
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('ews_alert_acknowledged', $delivery->failure_code);
    }

    public function test_pekerjaan_ews_dilewati_jika_telah_ditangani_admin(): void
    {
        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $employee = Employee::factory()->create();

        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_HANDLED,
        ]);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-handled',
            'employee_id' => $employee->id,
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('ews_alert_not_active', $delivery->failure_code);
    }

    public function test_pekerjaan_ews_dilewati_jika_ditandai_tidak_perlu(): void
    {
        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $employee = Employee::factory()->create();

        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_NOT_NEEDED,
        ]);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-not-needed',
            'employee_id' => $employee->id,
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('ews_alert_not_active', $delivery->failure_code);
    }

    public function test_pekerjaan_ews_kenaikan_pangkat_dilewati_jika_menjadi_non_eligible(): void
    {
        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $employee = Employee::factory()->create([
            'is_kinerja_baik' => false,
        ]);

        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-not-eligible',
            'employee_id' => $employee->id,
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('ews_promotion_not_eligible', $delivery->failure_code);
    }

    public function test_pekerjaan_ews_berhasil_dikirim_jika_status_masih_aktif_dan_belum_diakui(): void
    {
        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $employee = Employee::factory()->create(['no_hp' => '08123456789']);

        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            'notification_acknowledged_at' => null,
        ]);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-success',
            'employee_id' => $employee->id,
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertSentCount(1);
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_DELIVERED, $delivery->refresh()->status, 'Failure code: '.$delivery->refresh()->failure_code);
    }

    public function test_pekerjaan_ews_satyalancana_dilewati_jika_kelayakan_terbaru_dicabut(): void
    {
        $this->enableWhatsAppForEvent('ews.satyalancana');
        $employee = Employee::factory()->create([
            'no_hp' => '08123456789',
            'is_satyalancana_eligible' => true,
        ]);
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'SATYALANCANA',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
            'satyalancana_years' => 10,
        ]);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-satyalancana-not-eligible',
            'employee_id' => $employee->id,
            'event_key' => 'ews.satyalancana',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);
        $employee->forceFill(['is_satyalancana_eligible' => false])->save();
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'ews.satyalancana',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('ews_satyalancana_not_eligible', $delivery->failure_code);
    }

    public function test_pekerjaan_ews_dilewati_jika_admin_kehilangan_akses(): void
    {
        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $employeeTarget = Employee::factory()->create(['no_hp' => '08123456789']);
        $employeeAdmin = Employee::factory()->create(['no_hp' => '08987654321']);

        // Admin awalnya punya role, tapi kemudian role dicabut (disimulasikan dengan tidak membuat record User admin_kepegawaian)
        // Kita hanya membuat user dengan role biasa, atau tidak ada user
        User::factory()->create([
            'employee_id' => $employeeAdmin->id,
            'role' => 'pegawai_biasa', // Bukan admin_kepegawaian
        ]);

        $alert = EwsAlert::create([
            'employee_id' => $employeeTarget->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-admin-lost-access',
            'employee_id' => $employeeAdmin->id, // Dikirim ke admin
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employeeAdmin->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('ews_recipient_no_longer_authorized', $delivery->failure_code);
    }

    public function test_admin_ews_dengan_kelompok_aktif_ternormalisasi_tetap_menerima_whatsapp(): void
    {
        $this->enableWhatsAppForEvent('ews.kgb');
        $target = Employee::factory()->create();
        $admin = Employee::factory()->create(['no_hp' => '08123456789']);
        User::factory()->create(['employee_id' => $admin->id, 'role' => 'admin_kepegawaian']);

        // Data legacy dapat memiliki kapitalisasi dan spasi yang tidak kanonis.
        DB::table('ref_status_pegawai')
            ->where('id', $admin->status_pegawai_id)
            ->update(['kelompok' => ' AKTIF ']);

        $alert = EwsAlert::create([
            'employee_id' => $target->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => null,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'ews-admin-kelompok-aktif-ternormalisasi',
            'employee_id' => $admin->id,
            'event_key' => 'ews.kgb',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $admin->id,
            eventKey: 'ews.kgb',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: [],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertSentCount(1);
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_DELIVERED, $delivery->refresh()->status);
    }

    public function test_eligibility_promosi_ews_admin_dihitung_dari_pemilik_alert_bukan_penerima(): void
    {
        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $target = Employee::factory()->create(['is_kinerja_baik' => true]);
        $admin = Employee::factory()->create(['is_kinerja_baik' => false]);
        User::factory()->create(['employee_id' => $admin->id, 'role' => 'admin_kepegawaian']);

        $alert = EwsAlert::create([
            'employee_id' => $target->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-target-eligible-admin-not',
            'employee_id' => $admin->id,
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $admin->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Target EWS'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: config('services.whatsapp.templates.simpeg_ews_pengingat.variables_map'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertSentCount(1);
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_DELIVERED, $delivery->refresh()->status);
    }

    public function test_eligibility_promosi_ews_admin_tidak_menimpa_ketidaklayakan_pemilik_alert(): void
    {
        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $target = Employee::factory()->create(['is_kinerja_baik' => false]);
        $admin = Employee::factory()->create(['is_kinerja_baik' => true]);
        User::factory()->create(['employee_id' => $admin->id, 'role' => 'admin_kepegawaian']);

        $alert = EwsAlert::create([
            'employee_id' => $target->id,
            'type' => 'KENAIKAN_PANGKAT',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-target-not-eligible-admin-is',
            'employee_id' => $admin->id,
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $admin->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Target EWS'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            ewsAlertId: $alert->id,
            variablesMap: config('services.whatsapp.templates.simpeg_ews_pengingat.variables_map'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('ews_promotion_not_eligible', $delivery->failure_code);
    }

    public function test_job_dilewati_bila_konteks_cuti_atau_ews_tidak_tersedia(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-cuti-context-missing',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame('leave_request_context_missing', $delivery->refresh()->failure_code);

        $this->enableWhatsAppForEvent('ews.kenaikan_pangkat');
        $ewsDelivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-ews-context-missing',
            'employee_id' => $employee->id,
            'event_key' => 'ews.kenaikan_pangkat',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $ewsJob = new SendWhatsAppNotificationJob(
            idempotencyKey: $ewsDelivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'ews.kenaikan_pangkat',
            templateKey: 'simpeg_ews_pengingat',
            templateId: 'tmpl_ews_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerEwsBodyVariables(),
            buttonVariables: $this->providerEwsButtonVariables(),
            variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
        );

        $ewsJob->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame('ews_context_missing', $ewsDelivery->refresh()->failure_code);
    }

    public function test_job_lama_tanpa_snapshot_variables_map_dilewati_secara_fail_closed(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee);
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-variables-map-missing',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            leaveRequestId: $leaveRequest->id,
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame('template_contract_invalid', $delivery->refresh()->failure_code);
    }

    public function test_rollover_agregat_dilewati_bila_satu_pengajuan_telah_berubah_status(): void
    {
        $this->enableWhatsAppForEvent('cuti.dikembalikan_karena_rollover');
        $employee = Employee::factory()->create();
        $returned = $this->createLeaveRequest($employee, LeaveRequest::STATUS_RETURNED_FOR_ROLLOVER);
        $resubmitted = $this->createLeaveRequest($employee, 'menunggu_approval');
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'idempotency-rollover-aggregate-stale',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.dikembalikan_karena_rollover',
            'template_key' => 'simpeg_cuti_status',
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.dikembalikan_karena_rollover',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: ['nama_pegawai' => 'Ahmad'],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            leaveRequestId: $returned->id,
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
            leaveRequestIds: [$returned->id, $resubmitted->id],
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame('status_changed_before_delivery', $delivery->refresh()->failure_code);
    }

    public function test_job_tindakan_memverifikasi_langkah_aktif_yang_tepat_saat_approver_muncul_kembali(): void
    {
        $this->enableWhatsAppForEvent('cuti.pengajuan_baru');
        config(['services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => [
            'id' => 'tmpl_cuti_tindakan_123',
            'language' => 'id',
            'variables_map' => [
                'nama_pegawai' => '1', 'jenis_cuti' => '2', 'tanggal_mulai' => '3',
                'tanggal_selesai' => '4', 'jumlah_hari' => '5', 'tautan_detail' => '6',
            ],
            'button' => ['type' => 'url', 'parameter' => 'button_target_url'],
        ]]);

        $applicant = Employee::factory()->create();
        $approverA = Employee::factory()->create();
        $approverB = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($applicant, 'menunggu_approval');
        $oldStep = LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 1,
            'step_type' => 'verifikator',
            'role_label' => 'Verifikator',
            'approver_employee_id' => $approverA->id,
            'status' => 'approved',
            'is_final' => false,
        ]);
        LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 2,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approverB->id,
            'status' => 'approved',
            'is_final' => false,
        ]);
        $currentStep = LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 3,
            'step_type' => 'pimpinan',
            'role_label' => 'Pimpinan',
            'approver_employee_id' => $approverA->id,
            'status' => 'active',
            'is_final' => true,
        ]);

        $oldDelivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'approval-step-lama',
            'employee_id' => $approverA->id,
            'event_key' => 'cuti.pengajuan_baru',
            'template_key' => 'simpeg_cuti_perlu_tindakan',
            'status' => 'queued',
        ]);
        $newDelivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'approval-step-baru',
            'employee_id' => $approverA->id,
            'event_key' => 'cuti.pengajuan_baru',
            'template_key' => 'simpeg_cuti_perlu_tindakan',
            'status' => 'queued',
        ]);

        foreach ([[$oldDelivery, $oldStep], [$newDelivery, $currentStep]] as [$delivery, $step]) {
            $job = new SendWhatsAppNotificationJob(
                idempotencyKey: $delivery->idempotency_key,
                employeeId: $approverA->id,
                eventKey: 'cuti.pengajuan_baru',
                templateKey: 'simpeg_cuti_perlu_tindakan',
                templateId: 'tmpl_cuti_tindakan_123',
                language: 'id',
                variables: [],
                bodyVariables: [
                    '1' => 'Pemohon', '2' => 'Cuti Tahunan', '3' => '01 September 2026',
                    '4' => '03 September 2026', '5' => '3 hari kerja', '6' => 'https://simpeg.example.test/cuti/approval',
                ],
                buttonVariables: ['button_target_url' => 'https://simpeg.example.test/cuti/approval'],
                leaveRequestId: $leaveRequest->id,
                leaveRequestStepId: $step->id,
                leaveRequestVersion: $leaveRequest->updated_at?->utc()->format('Y-m-d\TH:i:s.u\Z'),
                variablesMap: $this->providerVariablesMap('simpeg_cuti_perlu_tindakan'),
            );
            $job->handle(
                app(WhatsAppReadiness::class),
                app(NotificationChannelResolver::class),
                app(NotificationEventCatalog::class),
                app(WhatsAppRecipientResolver::class),
                $this->fakeAdapter,
            );
        }

        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $oldDelivery->refresh()->status);
        $this->assertSame('approver_no_longer_active', $oldDelivery->failure_code);
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_DELIVERED, $newDelivery->refresh()->status);
        $this->fakeAdapter->assertSentCount(1);
    }

    public function test_job_tindakan_lama_dilewati_setelah_pengajuan_diubah_dan_dikirim_ulang(): void
    {
        $this->enableWhatsAppForEvent('cuti.pengajuan_baru');
        config(['services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => [
            'id' => 'tmpl_cuti_tindakan_123',
            'language' => 'id',
            'variables_map' => [
                'nama_pegawai' => '1', 'jenis_cuti' => '2', 'tanggal_mulai' => '3',
                'tanggal_selesai' => '4', 'jumlah_hari' => '5', 'tautan_detail' => '6',
            ],
            'button' => ['type' => 'url', 'parameter' => 'button_target_url'],
        ]]);

        $applicant = Employee::factory()->create();
        $approver = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($applicant, 'menunggu_approval');
        $step = LeaveRequestStep::create([
            'leave_request_id' => $leaveRequest->id,
            'step_order' => 1,
            'step_type' => 'kepala_bagian',
            'role_label' => 'Kepala Bagian',
            'approver_employee_id' => $approver->id,
            'status' => 'active',
            'is_final' => true,
        ]);
        $queuedVersion = $leaveRequest->updated_at?->utc()->format('Y-m-d\\TH:i:s.u\\Z');

        // Perubahan lalu resubmit mempertahankan step aktif yang sama, tetapi mengubah data pengajuan.
        $leaveRequest->forceFill(['status' => 'perlu_perubahan'])->save();
        $leaveRequest->forceFill([
            'status' => 'menunggu_approval',
            'tanggal_mulai' => '2026-09-10',
            'tanggal_selesai' => '2026-09-12',
            'jumlah_hari_kerja' => 3,
            'updated_at' => now()->addSecond(),
        ])->save();

        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'tindakan-versi-lama',
            'employee_id' => $approver->id,
            'event_key' => 'cuti.pengajuan_baru',
            'template_key' => 'simpeg_cuti_perlu_tindakan',
            'status' => 'queued',
        ]);
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $approver->id,
            eventKey: 'cuti.pengajuan_baru',
            templateKey: 'simpeg_cuti_perlu_tindakan',
            templateId: 'tmpl_cuti_tindakan_123',
            language: 'id',
            variables: [],
            bodyVariables: [
                '1' => 'Pemohon', '2' => 'Cuti Tahunan', '3' => '01 September 2026',
                '4' => '03 September 2026', '5' => '3 hari kerja', '6' => 'https://simpeg.example.test/cuti/approval',
            ],
            buttonVariables: ['button_target_url' => 'https://simpeg.example.test/cuti/approval'],
            leaveRequestId: $leaveRequest->id,
            leaveRequestStepId: $step->id,
            leaveRequestVersion: $queuedVersion,
            variablesMap: $this->providerVariablesMap('simpeg_cuti_perlu_tindakan'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
        $this->assertSame('leave_request_version_changed', $delivery->failure_code);
    }

    public function test_job_ews_non_promosi_dilewati_bila_pemilik_alert_sudah_nonaktif(): void
    {
        $inactiveStatus = RefStatusPegawai::create([
            'kode' => 'NONAKTIF_TEST_WHATSAPP',
            'nama' => 'Nonaktif Test WhatsApp',
            'kelompok' => 'Tidak Aktif',
            'is_default' => false,
            'is_active' => true,
        ]);

        foreach ([
            'ews.kgb' => 'KGB',
            'ews.pensiun' => 'PENSIUN',
            'ews.kontrak_pppk' => 'KONTRAK_PPPK',
            'ews.satyalancana' => 'SATYALANCANA',
        ] as $eventKey => $alertType) {
            $this->enableWhatsAppForEvent($eventKey);
            $owner = Employee::factory()->create();
            $admin = Employee::factory()->create();
            User::factory()->create(['employee_id' => $admin->id, 'role' => 'admin_kepegawaian']);
            $alert = EwsAlert::create([
                'employee_id' => $owner->id,
                'type' => $alertType,
                'target_date' => now()->addDays(30)->toDateString(),
                'interval_days' => 30,
                'is_eligible' => true,
                'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
                'satyalancana_years' => $alertType === 'SATYALANCANA' ? 10 : null,
            ]);
            // Owner tetap dipertahankan agar guard runtime benar-benar menguji
            // status lifecycle, bukan alert yang ikut terhapus oleh FK cascade.
            $owner->update(['status_pegawai_id' => $inactiveStatus->id]);
            $delivery = WhatsAppNotificationDelivery::create([
                'idempotency_key' => "ews-owner-nonaktif:{$eventKey}",
                'employee_id' => $admin->id,
                'event_key' => $eventKey,
                'template_key' => 'simpeg_ews_pengingat',
                'status' => 'queued',
            ]);
            $job = new SendWhatsAppNotificationJob(
                idempotencyKey: $delivery->idempotency_key,
                employeeId: $admin->id,
                eventKey: $eventKey,
                templateKey: 'simpeg_ews_pengingat',
                templateId: 'tmpl_ews_123',
                language: 'id',
                variables: [],
                bodyVariables: $this->providerEwsBodyVariables(),
                buttonVariables: $this->providerEwsButtonVariables(),
                ewsAlertId: $alert->id,
                variablesMap: $this->providerVariablesMap('simpeg_ews_pengingat'),
            );

            $job->handle(
                app(WhatsAppReadiness::class),
                app(NotificationChannelResolver::class),
                app(NotificationEventCatalog::class),
                app(WhatsAppRecipientResolver::class),
                $this->fakeAdapter,
            );

            $this->assertSame(WhatsAppNotificationDelivery::STATUS_SKIPPED, $delivery->refresh()->status);
            $this->assertSame('ews_target_not_found', $delivery->failure_code);
        }

        $this->fakeAdapter->assertNotSent();
    }

    public function test_job_duplikat_tidak_melebihi_batas_global_upaya_provider(): void
    {
        $this->enableWhatsAppForEvent('cuti.disetujui');
        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequest($employee, 'disetujui');
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => 'provider-attempt-terminal',
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => WhatsAppNotificationDelivery::STATUS_FAILED,
            'attempt_count' => 3,
            'failure_code' => 'max_retries_exceeded',
        ]);
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: [],
            bodyVariables: $this->providerBodyVariables(),
            buttonVariables: $this->providerButtonVariables(),
            leaveRequestId: $leaveRequest->id,
            variablesMap: $this->providerVariablesMap('simpeg_cuti_status'),
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
        );

        $this->fakeAdapter->assertNotSent();
        $this->assertSame(3, $delivery->refresh()->attempt_count);
        $this->assertSame('max_retries_exceeded', $delivery->failure_code);
    }
}
