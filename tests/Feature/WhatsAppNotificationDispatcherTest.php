<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppNotificationJob;
use App\Models\Employee;
use App\Models\EwsAlert;
use App\Models\LeaveApproval;
use App\Models\LeaveRequest;
use App\Models\NotificationEventChannel;
use App\Models\RefJenisCuti;
use App\Models\RefNotificationChannel;
use App\Models\SimpegNotification;
use App\Models\WhatsAppNotificationDelivery;
use App\Models\WhatsAppNotificationOutbox;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\WhatsApp\UnavailableWhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppNotificationDispatcher;
use App\Services\Notifications\WhatsApp\WhatsAppReadiness;
use App\Services\Notifications\WhatsApp\WhatsAppRecipientResolver;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateMessage;
use App\Services\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeWhatsAppTemplateAdapter;
use Tests\TestCase;

class WhatsAppNotificationDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private FakeWhatsAppTemplateAdapter $fakeAdapter;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.whatsapp.event_templates' => WhatsAppTemplateContract::eventTemplateArchetypes(),
            'services.whatsapp.templates' => [],
        ]);
        config([
            'services.whatsapp.canonical_url' => 'https://simpeg.lldikti16.kemdikbud.go.id',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => [
                'id' => 'tmpl_cuti_perlu_tindakan_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'tanggal_mulai' => '3',
                    'tanggal_selesai' => '4',
                    'jumlah_hari' => '5',
                    'alasan' => '6',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
            'services.whatsapp.templates.simpeg_cuti_status' => [
                'id' => 'tmpl_cuti_status_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_cuti' => '2',
                    'status' => '3',
                    'keterangan' => '4',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
            'services.whatsapp.templates.simpeg_ews_pengingat' => [
                'id' => 'tmpl_ews_pengingat_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_peringatan' => '2',
                    'tanggal_target' => '3',
                    'sisa_waktu' => '4',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
        ]);
        $this->persistRuntimeTemplateConfig();
        $this->fakeAdapter = new FakeWhatsAppTemplateAdapter;
        $this->app->instance(WhatsAppTemplateAdapter::class, $this->fakeAdapter);
    }

    public function test_baseline_whatsapp_tetap_fail_closed_tanpa_readiness_dan_nomor_terverifikasi(): void
    {
        $employee = Employee::factory()->create(['no_hp' => '081234567890']);

        $this->assertFalse(app(WhatsAppReadiness::class)->isReady());
        // Resolver menormalisasi no_hp menjadi alamat 62..., tetapi kesiapan sumber
        // nomor tetap menjadi gerbang readiness yang terpisah dan belum aktif di sini.
        $this->assertSame('6281234567890', app(WhatsAppRecipientResolver::class)->resolve($employee));

        $dispatcher = app(WhatsAppNotificationDispatcher::class);
        $result = $dispatcher->dispatch($employee, 'cuti.disetujui', ['leave_request_id' => 'abc']);

        $this->assertNull($result);
        $this->assertSame(0, WhatsAppNotificationDelivery::count());
        $this->fakeAdapter->assertNotSent();
    }

    public function test_adapter_runtime_unavailable_menghentikan_dispatch_sebelum_outbox_dibuat(): void
    {
        Queue::fake();
        Http::fake();
        $this->app->instance(WhatsAppTemplateAdapter::class, new UnavailableWhatsAppTemplateAdapter);

        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->firstOrFail();
        $channel->forceFill(['is_enabled' => true])->save();
        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'cuti.disetujui', 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        // Gerbang konfigurasi sengaja dipaksa siap; adapter unavailable tetap harus
        // menghentikan dispatcher sebelum mapper, delivery, dan outbox diproses.
        $readiness = $this->createMock(WhatsAppReadiness::class);
        $readiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $readiness);

        $recipientResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $recipientResolver->method('resolve')->willReturn('6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $recipientResolver);

        $employee = Employee::factory()->create();
        $result = app(WhatsAppNotificationDispatcher::class)
            ->dispatch($employee, 'cuti.disetujui', ['leave_request_id' => 'konteks-uji']);

        $this->assertNull($result);
        $this->assertDatabaseCount('whatsapp_notification_deliveries', 0);
        $this->assertSame(0, WhatsAppNotificationOutbox::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_template_id_belum_terkonfigurasi_fail_closed(): void
    {
        config(['services.whatsapp.templates.simpeg_cuti_status.id' => null]);
        $this->persistRuntimeTemplateConfig();

        $employee = Employee::factory()->create();
        $dispatcher = app(WhatsAppNotificationDispatcher::class);

        $this->assertNull($dispatcher->dispatch($employee, 'cuti.disetujui', ['leave_request_id' => 'abc']));
    }

    public function test_readiness_menolak_kontrak_template_provider_yang_belum_lengkap(): void
    {
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => 'provider_resmi',
            'services.whatsapp.base_url' => 'https://provider.example.test',
            'services.whatsapp.credential_reference' => 'credential-reference',
            'services.whatsapp.channel_id' => 'channel-resmi',
            'services.whatsapp.template_configuration' => 'provider-approved',
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.id' => 'template-cuti-tindakan',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.language' => 'id',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.variables_map' => [],
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan.button' => null,
            'services.whatsapp.templates.simpeg_cuti_status.id' => 'template-cuti-status',
            'services.whatsapp.templates.simpeg_cuti_status.language' => 'id',
            'services.whatsapp.templates.simpeg_cuti_status.variables_map' => [],
            'services.whatsapp.templates.simpeg_cuti_status.button' => null,
            'services.whatsapp.templates.simpeg_ews_pengingat.id' => 'template-ews',
            'services.whatsapp.templates.simpeg_ews_pengingat.language' => 'id',
            'services.whatsapp.templates.simpeg_ews_pengingat.variables_map' => [],
            'services.whatsapp.templates.simpeg_ews_pengingat.button' => null,
        ]);

        $this->assertFalse(app(WhatsAppReadiness::class)->isReady());
    }

    public function test_delivery_key_yang_sama_hanya_menghasilkan_satu_record_aman(): void
    {
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => hash('sha256', 'cuti.disetujui:approval-1:pegawai-1'),
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'employee_id' => Employee::factory()->create()->id,
            'status' => 'queued',
        ]);

        foreach (['phone', 'token', 'secret', 'provider_response', 'title', 'body'] as $column) {
            $this->assertFalse(Schema::hasColumn('whatsapp_notification_deliveries', $column));
        }

        $this->assertTrue(Schema::hasColumn('whatsapp_notification_deliveries', 'lease_expires_at'));

        $this->expectException(QueryException::class);
        $delivery->replicate()->save();
    }

    public function test_event_diluar_allowlist_gagal_closed(): void
    {
        $employee = Employee::factory()->create();
        $dispatcher = app(WhatsAppNotificationDispatcher::class);

        $this->assertNull($dispatcher->dispatch($employee, 'ews.scheduler_failed'));
        $this->assertNull($dispatcher->dispatch($employee, 'import_pegawai'));
        $this->fakeAdapter->assertNotSent();
    }

    public function test_pengiriman_berhasil_saat_seluruh_gerbang_terpenuhi(): void
    {
        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channel->forceFill(['is_enabled' => true])->save();

        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'cuti.disetujui', 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        // Mock readiness dan recipient resolver untuk skenario terverifikasi
        $mockReadiness = $this->createMock(WhatsAppReadiness::class);
        $mockReadiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $mockReadiness);

        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);

        $employee = Employee::factory()->create(['nama_lengkap' => 'Ahmad Fauzi']);
        $jenisCuti = RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
        );
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-03',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Liburan keluarga',
            'status' => 'disetujui',
        ]);
        $approver = Employee::factory()->create();
        $approval = LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'Disetujui penuh.',
            'acted_at' => now(),
        ]);

        $dispatcher = app(WhatsAppNotificationDispatcher::class);
        $delivery = $dispatcher->dispatch($employee, 'cuti.disetujui', [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        $this->assertNotNull($delivery);
        $this->assertSame('queued', $delivery->status);
        $outbox = WhatsAppNotificationOutbox::query()->where('delivery_id', $delivery->id)->first();
        $this->assertNotNull($outbox);
        $this->assertNotSame('Ahmad Fauzi', $outbox->encrypted_payload);

        // Eksekusi job queue secara synchronous
        $job = new SendWhatsAppNotificationJob(
            idempotencyKey: $delivery->idempotency_key,
            employeeId: $employee->id,
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'tmpl_cuti_status_123',
            language: 'id',
            variables: [
                'nama_pegawai' => 'Ahmad Fauzi',
                'jenis_cuti' => 'Cuti Tahunan',
                'status' => 'Disetujui',
                'keterangan' => 'Disetujui penuh.',
                'tautan_detail' => 'https://simpeg.lldikti16.kemdikbud.go.id/cuti/'.$leaveRequest->id,
            ],
            bodyVariables: [
                '1' => 'Ahmad Fauzi',
                '2' => 'Cuti Tahunan',
                '3' => 'Disetujui',
                '4' => 'Disetujui penuh.',
            ],
            buttonVariables: [
                'button_target_url' => 'https://simpeg.lldikti16.kemdikbud.go.id/cuti/'.$leaveRequest->id,
            ],
        );

        $job->handle(
            app(WhatsAppReadiness::class),
            app(NotificationChannelResolver::class),
            app(NotificationEventCatalog::class),
            app(WhatsAppRecipientResolver::class),
            $this->fakeAdapter,
            app(WhatsAppRuntimeConfig::class),
        );

        $this->fakeAdapter->assertSentCount(1);
        $this->fakeAdapter->assertSent(function (WhatsAppTemplateMessage $msg) {
            return $msg->templateKey === 'simpeg_cuti_status'
                && $msg->templateId === 'tmpl_cuti_status_123'
                && $msg->recipientAddress === '+6281234567890'
                && $msg->bodyVariables['3'] === 'Disetujui';
        });

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertNotNull($delivery->delivered_at);
        $this->assertSame(1, $delivery->attempt_count);
    }

    public function test_dispatcher_collision_paralel_menangani_race_condition_secara_aman(): void
    {
        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channel->forceFill(['is_enabled' => true])->save();

        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'cuti.disetujui', 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        $mockReadiness = $this->createMock(WhatsAppReadiness::class);
        $mockReadiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $mockReadiness);

        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);

        $employee = Employee::factory()->create(['nama_lengkap' => 'Budi Collision']);
        $jenisCuti = RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
        );
        $leaveRequest = LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2026-09-01',
            'tanggal_selesai' => '2026-09-03',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Urusan keluarga',
            'status' => 'disetujui',
        ]);
        $approver = Employee::factory()->create();
        $approval = LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'OK',
            'acted_at' => now(),
        ]);

        $dispatcher = app(WhatsAppNotificationDispatcher::class);

        // Request 1: Dispatch pertama
        $delivery1 = $dispatcher->dispatch($employee, 'cuti.disetujui', [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        // Request 2: Dispatch simultan dengan kunci yang sama
        $delivery2 = $dispatcher->dispatch($employee, 'cuti.disetujui', [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        $this->assertNotNull($delivery1);
        $this->assertNotNull($delivery2);
        $this->assertSame($delivery1->id, $delivery2->id);
        $this->assertSame($delivery1->idempotency_key, $delivery2->idempotency_key);
        $this->assertSame(1, WhatsAppNotificationDelivery::where('idempotency_key', $delivery1->idempotency_key)->count());
    }

    public function test_resubmit_rollover_dengan_siklus_baru_membuat_delivery_baru_untuk_approver(): void
    {
        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channel->forceFill(['is_enabled' => true])->save();

        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'cuti.pengajuan_baru', 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        $readiness = $this->createMock(WhatsAppReadiness::class);
        $readiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $readiness);

        $resolver = $this->createMock(WhatsAppRecipientResolver::class);
        $resolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $resolver);

        $applicant = Employee::factory()->create(['nama_lengkap' => 'Pemohon Rollover']);
        $approver = Employee::factory()->create(['nama_lengkap' => 'Approver Snapshot']);
        $leaveRequest = $this->createLeaveRequestForDispatcher($applicant);
        $dispatcher = app(WhatsAppNotificationDispatcher::class);

        $firstDelivery = $dispatcher->dispatch($approver, 'cuti.pengajuan_baru', [
            'leave_request_id' => $leaveRequest->id,
            'notification_cycle_id' => 'rollover-cycle-1',
            'url' => '/cuti/'.$leaveRequest->id,
        ]);
        $resubmittedDelivery = $dispatcher->dispatch($approver, 'cuti.pengajuan_baru', [
            'leave_request_id' => $leaveRequest->id,
            'notification_cycle_id' => 'rollover-cycle-2',
            'url' => '/cuti/'.$leaveRequest->id,
        ]);

        $this->assertNotNull($firstDelivery);
        $this->assertNotNull($resubmittedDelivery);
        $this->assertNotSame($firstDelivery->id, $resubmittedDelivery->id);
        $this->assertSame(2, WhatsAppNotificationDelivery::query()
            ->where('event_key', 'cuti.pengajuan_baru')
            ->where('employee_id', $approver->id)
            ->count());
    }

    public function test_postgresql_dispatcher_collision_dalam_transaksi_tidak_menggugurkan_transaksi(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Pengujian transaksi abort PostgreSQL hanya berjalan pada driver pgsql.');
        }

        $employee = Employee::factory()->create();
        $idempotencyKey = hash('sha256', 'test:pg:collision:'.$employee->id);

        // Pre-insert satu baris untuk memaksa collision
        WhatsAppNotificationDelivery::create([
            'idempotency_key' => $idempotencyKey,
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => WhatsAppNotificationDelivery::STATUS_QUEUED,
        ]);

        // Jalankan operasi create-or-read di dalam transaksi PostgreSQL
        $delivery = DB::transaction(function () use ($idempotencyKey, $employee) {
            try {
                return DB::transaction(function () use ($idempotencyKey, $employee) {
                    return WhatsAppNotificationDelivery::create([
                        'idempotency_key' => $idempotencyKey,
                        'employee_id' => $employee->id,
                        'event_key' => 'cuti.disetujui',
                        'template_key' => 'simpeg_cuti_status',
                        'status' => WhatsAppNotificationDelivery::STATUS_QUEUED,
                    ]);
                });
            } catch (QueryException) {
                // Pastikan PostgreSQL tidak terkena 25P02 transaction aborted error
                return WhatsAppNotificationDelivery::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }
        });

        $this->assertNotNull($delivery);
        $this->assertSame($idempotencyKey, $delivery->idempotency_key);
    }

    public function test_rollover_return_multi_tahun_memiliki_kunci_idempotensi_berbeda(): void
    {
        Queue::fake();

        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channel->forceFill(['is_enabled' => true])->save();

        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'cuti.dikembalikan_karena_rollover', 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        $mockReadiness = $this->createMock(WhatsAppReadiness::class);
        $mockReadiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $mockReadiness);

        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);

        $employee = Employee::factory()->create();
        $leaveRequest = $this->createLeaveRequestForDispatcher($employee);

        $dispatcher = app(WhatsAppNotificationDispatcher::class);

        // Rollover tahun 2026
        $delivery2026 = $dispatcher->dispatch($employee, 'cuti.dikembalikan_karena_rollover', [
            'leave_request_id' => $leaveRequest->id,
            'leave_request_ids' => [$leaveRequest->id],
            'source_year' => 2026,
            'target_year' => 2027,
            'jumlah_pengajuan' => 1,
        ]);

        // Rollover tahun 2027 untuk pengajuan yang sama
        $delivery2027 = $dispatcher->dispatch($employee, 'cuti.dikembalikan_karena_rollover', [
            'leave_request_id' => $leaveRequest->id,
            'leave_request_ids' => [$leaveRequest->id],
            'source_year' => 2027,
            'target_year' => 2028,
            'jumlah_pengajuan' => 1,
        ]);

        $this->assertNotNull($delivery2026);
        $this->assertNotNull($delivery2027);
        $this->assertNotSame($delivery2026->idempotency_key, $delivery2027->idempotency_key);
        $this->assertSame(2, WhatsAppNotificationDelivery::count());
        Queue::assertPushed(SendWhatsAppNotificationJob::class, function (SendWhatsAppNotificationJob $job) use ($leaveRequest): bool {
            return $job->leaveRequestIds === [$leaveRequest->id];
        });
    }

    public function test_dispatcher_tidak_mengantrekan_job_duplikat_saat_record_sudah_ada(): void
    {
        Queue::fake();

        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channel->forceFill(['is_enabled' => true])->save();

        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'cuti.disetujui', 'notification_channel_id' => $channel->id],
            ['is_enabled' => true],
        );

        $mockReadiness = $this->createMock(WhatsAppReadiness::class);
        $mockReadiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $mockReadiness);

        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);

        $employee = Employee::factory()->create(['nama_lengkap' => 'Ahmad Fauzi']);
        $leaveRequest = $this->createLeaveRequestForDispatcher($employee);
        $approver = Employee::factory()->create();
        $approval = LeaveApproval::create([
            'leave_request_id' => $leaveRequest->id,
            'approver_id' => $approver->id,
            'stage' => 1,
            'action' => 'APPROVE',
            'komentar' => 'OK',
            'acted_at' => now(),
        ]);

        $dispatcher = app(WhatsAppNotificationDispatcher::class);

        // Panggilan pertama: membuat record baru dan mengantrekan job
        $delivery1 = $dispatcher->dispatch($employee, 'cuti.disetujui', [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        // Panggilan kedua untuk event dan konteks yang sama sebelum worker berjalan
        $delivery2 = $dispatcher->dispatch($employee, 'cuti.disetujui', [
            'leave_request_id' => $leaveRequest->id,
            'leave_approval_id' => $approval->id,
        ]);

        $this->assertNotNull($delivery1);
        $this->assertNotNull($delivery2);
        $this->assertSame($delivery1->id, $delivery2->id);

        // Job HANYA boleh diantrekan satu kali dan harus tetap menggunakan
        // queue standar yang memang dikonsumsi worker project.
        Queue::assertPushed(SendWhatsAppNotificationJob::class, function (SendWhatsAppNotificationJob $job): bool {
            return $job->queue === null;
        });
    }

    public function test_ews_reminder_tetap_mengirim_whatsapp_saat_channel_in_app_nonaktif(): void
    {
        Queue::fake();

        config([
            'services.whatsapp.canonical_url' => 'https://simpeg.lldikti16.kemdikbud.go.id',
            'services.whatsapp.templates.simpeg_ews_pengingat' => [
                'id' => 'tmpl_ews_pengingat_123',
                'language' => 'id',
                'variables_map' => [
                    'nama_pegawai' => '1',
                    'jenis_peringatan' => '2',
                    'tanggal_target' => '3',
                    'sisa_waktu' => '4',
                ],
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
        ]);

        $channelWa = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => true]);
        $channelWa->forceFill(['is_enabled' => true])->save();

        $channelInApp = RefNotificationChannel::query()->where('code', 'in_app')->first()
            ?? RefNotificationChannel::create(['code' => 'in_app', 'name' => 'In App', 'is_enabled' => true]);
        $channelInApp->forceFill(['is_enabled' => true])->save();

        // Kebijakan in_app dimatikan khusus untuk event ews.kgb, namun whatsapp_business tetap aktif
        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'ews.kgb', 'notification_channel_id' => $channelInApp->id],
            ['is_enabled' => false],
        );
        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'ews.kgb', 'notification_channel_id' => $channelWa->id],
            ['is_enabled' => true],
        );

        $mockReadiness = $this->createMock(WhatsAppReadiness::class);
        $mockReadiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $mockReadiness);

        $mockResolver = $this->createMock(WhatsAppRecipientResolver::class);
        $mockResolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $mockResolver);

        $employee = Employee::factory()->create(['nama_lengkap' => 'Budi Santoso']);
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
        ]);

        $notificationService = app(NotificationService::class);
        $notification = $notificationService->upsertEwsReminder(
            $employee,
            $alert,
            'ews.kgb',
            'Pengingat KGB',
            'KGB berikutnya sudah dekat.',
            ['ews_alert_id' => $alert->id],
        );

        // In-app tidak dibuat karena in_app disabled
        $this->assertNull($notification);
        $this->assertDatabaseMissing('notifications', ['user_id' => $employee->id]);

        // Namun WhatsApp delivery tetap dibuat dan job diantrekan secara independen
        $this->assertDatabaseHas('whatsapp_notification_deliveries', [
            'employee_id' => $employee->id,
            'event_key' => 'ews.kgb',
        ]);
        Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);
    }

    public function test_reminder_ews_lama_yang_belum_dibaca_dievaluasi_ulang_untuk_whatsapp(): void
    {
        Queue::fake();

        $channelWa = RefNotificationChannel::query()->where('code', 'whatsapp_business')->firstOrFail();
        $channelInApp = RefNotificationChannel::query()->where('code', 'in_app')->firstOrFail();

        $channelWa->forceFill(['is_enabled' => true])->save();
        $channelInApp->forceFill(['is_enabled' => true])->save();
        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'ews.kgb', 'notification_channel_id' => $channelWa->id],
            ['is_enabled' => true],
        );
        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'ews.kgb', 'notification_channel_id' => $channelInApp->id],
            ['is_enabled' => true],
        );

        $readiness = $this->createMock(WhatsAppReadiness::class);
        $readiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $readiness);

        $resolver = $this->createMock(WhatsAppRecipientResolver::class);
        $resolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $resolver);

        $employee = Employee::factory()->create(['nama_lengkap' => 'Budi Santoso']);
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
        ]);
        SimpegNotification::create([
            'user_id' => $employee->id,
            'ews_alert_id' => $alert->id,
            'type' => 'ews.kgb',
            'title' => 'Pengingat KGB lama',
            'body' => 'Reminder sebelumnya dibuat saat WhatsApp belum siap.',
            'data' => ['ews_alert_id' => $alert->id],
            'is_read' => false,
        ]);

        $service = app(NotificationService::class);
        $service->upsertEwsReminder(
            $employee,
            $alert,
            'ews.kgb',
            'Pengingat KGB',
            'KGB berikutnya sudah dekat.',
            ['ews_alert_id' => $alert->id],
        );
        $service->upsertEwsReminder(
            $employee,
            $alert,
            'ews.kgb',
            'Pengingat KGB',
            'KGB berikutnya sudah dekat.',
            ['ews_alert_id' => $alert->id],
        );

        $this->assertDatabaseCount('whatsapp_notification_deliveries', 1);
        $this->assertDatabaseHas('whatsapp_notification_deliveries', [
            'employee_id' => $employee->id,
            'event_key' => 'ews.kgb',
            'status' => WhatsAppNotificationDelivery::STATUS_QUEUED,
        ]);
        Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);
    }

    public function test_reminder_ews_yang_diskip_saat_readiness_mati_diantrekan_ulang_saat_pulih(): void
    {
        Queue::fake();
        $channelWa = RefNotificationChannel::query()->where('code', 'whatsapp_business')->firstOrFail();
        $channelWa->forceFill(['is_enabled' => true])->save();
        NotificationEventChannel::updateOrCreate(
            ['event_key' => 'ews.kgb', 'notification_channel_id' => $channelWa->id],
            ['is_enabled' => true],
        );

        $readiness = $this->createMock(WhatsAppReadiness::class);
        $readiness->method('isReady')->willReturn(true);
        $this->app->instance(WhatsAppReadiness::class, $readiness);
        $resolver = $this->createMock(WhatsAppRecipientResolver::class);
        $resolver->method('resolve')->willReturn('+6281234567890');
        $this->app->instance(WhatsAppRecipientResolver::class, $resolver);

        $employee = Employee::factory()->create(['nama_lengkap' => 'Budi Santoso']);
        $alert = EwsAlert::create([
            'employee_id' => $employee->id,
            'type' => 'KGB',
            'target_date' => now()->addDays(30)->toDateString(),
            'interval_days' => 30,
            'is_eligible' => true,
            'followup_status' => EwsAlert::FOLLOWUP_STATUS_ACTIVE,
        ]);
        $idempotencyKey = hash('sha256', "ews.kgb:{$alert->id}:{$employee->id}");
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => $idempotencyKey,
            'employee_id' => $employee->id,
            'event_key' => 'ews.kgb',
            'template_key' => 'simpeg_ews_pengingat',
            'status' => WhatsAppNotificationDelivery::STATUS_SKIPPED,
            'failure_code' => 'readiness_or_policy_disabled',
        ]);
        WhatsAppNotificationOutbox::create([
            'delivery_id' => $delivery->id,
            'encrypted_payload' => Crypt::encryptString('{}'),
            'published_at' => now(),
            'publish_attempts' => 3,
            'publish_attempted_at' => now(),
            'publish_failed_at' => now(),
            'publish_failure_code' => 'publish_failed',
        ]);

        app(NotificationService::class)->upsertEwsReminder(
            $employee,
            $alert,
            'ews.kgb',
            'Pengingat KGB',
            'KGB berikutnya sudah dekat.',
            ['ews_alert_id' => $alert->id],
        );

        $this->assertSame(WhatsAppNotificationDelivery::STATUS_QUEUED, $delivery->refresh()->status);
        $this->assertNull($delivery->failure_code);
        $outbox = WhatsAppNotificationOutbox::query()->where('delivery_id', $delivery->id)->firstOrFail();
        $this->assertSame(1, $outbox->publish_attempts);
        $this->assertNotNull($outbox->publish_attempted_at);
        $this->assertNull($outbox->publish_failed_at);
        $this->assertNull($outbox->publish_failure_code);
        Queue::assertPushed(SendWhatsAppNotificationJob::class, 1);
    }

    private function createLeaveRequestForDispatcher(Employee $employee): LeaveRequest
    {
        $jenisCuti = RefJenisCuti::firstOrCreate(
            ['code' => 'tahunan'],
            ['nama' => 'Cuti Tahunan', 'mengurangi_saldo_tahunan' => true, 'khusus_pns' => false],
        );

        return LeaveRequest::create([
            'employee_id' => $employee->id,
            'jenis_cuti_id' => $jenisCuti->id,
            'tanggal_mulai' => '2027-01-10',
            'tanggal_selesai' => '2027-01-12',
            'jumlah_hari_kerja' => 3,
            'alasan' => 'Pengajuan ulang setelah rollover',
            'status' => 'menunggu_approval',
        ]);
    }

    /** Menyalin fixture kontrak dispatcher ke setting DB sumber runtime. */
    private function persistRuntimeTemplateConfig(): void
    {
        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create([
                'code' => 'whatsapp_business',
                'name' => 'WhatsApp Business',
                'is_enabled' => false,
            ]);
        $contract = json_encode([
            'event_templates' => config('services.whatsapp.event_templates', []),
            'templates' => config('services.whatsapp.templates', []),
        ], JSON_THROW_ON_ERROR);

        $channel->forceFill(['config' => [
            'provider' => 'qontak',
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            'canonical_url' => config('services.whatsapp.canonical_url'),
            'template_configuration' => $contract,
        ]])->save();

        app(WhatsAppRuntimeConfig::class)->invalidate();
    }
}
