<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificationEventChannel;
use App\Models\RefNotificationChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NotificationChannelManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbac();
    }

    public function test_super_admin_dapat_membuat_channel_baru_dalam_keadaan_nonaktif_tanpa_config(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postWithCsrf('/data-master/channel-notifikasi', [
                'code' => 'sms_gateway',
                'name' => 'SMS Gateway',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $channel = RefNotificationChannel::query()->where('code', 'sms_gateway')->firstOrFail();

        $this->assertFalse($channel->is_enabled);
        $this->assertNull($channel->config);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CREATE',
            'auditable_type' => 'RefNotificationChannel',
            'auditable_id' => $channel->id,
        ]);
    }

    public function test_create_menolak_config_dan_kode_duplikat(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->postWithCsrf('/data-master/channel-notifikasi', [
                'code' => 'email',
                'name' => 'Email Duplikat',
                'config' => ['token' => 'rahasia'],
            ])
            ->assertSessionHasErrors(['code', 'config']);

        $this->assertSame(1, RefNotificationChannel::query()->where('code', 'email')->count());
        $this->assertDatabaseMissing('ref_notification_channels', ['name' => 'Email Duplikat']);
    }

    public function test_update_hanya_mengubah_nama_dan_menolak_perubahan_kode(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/update", [
                'name' => 'Surat Elektronik',
                'code' => 'email_baru',
            ])
            ->assertSessionHasErrors(['code']);

        $this->assertSame('email', $channel->refresh()->code);
        $this->assertNotSame('Surat Elektronik', $channel->name);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/update", [
                'name' => 'Surat Elektronik',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Surat Elektronik', $channel->refresh()->name);
        $this->assertSame('email', $channel->code);
    }

    public function test_channel_inti_tidak_dapat_dihapus(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach (['in_app', 'email', 'whatsapp_business'] as $code) {
            $channel = RefNotificationChannel::query()->where('code', $code)->firstOrFail();

            $this->actingAs($admin)
                ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/destroy", [])
                ->assertSessionHasErrors(['channel']);

            $this->assertDatabaseHas('ref_notification_channels', ['id' => $channel->id]);
        }
    }

    public function test_channel_noninti_yang_belum_dipakai_dapat_dihapus_dengan_audit(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = RefNotificationChannel::create([
            'code' => 'push_mobile',
            'name' => 'Push Mobile',
            'is_enabled' => false,
        ]);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/destroy", [])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('ref_notification_channels', ['id' => $channel->id]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'DELETE',
            'auditable_type' => 'RefNotificationChannel',
            'auditable_id' => $channel->id,
        ]);
    }

    public function test_channel_yang_dirujuk_kebijakan_event_tidak_dapat_dihapus(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = RefNotificationChannel::create([
            'code' => 'push_mobile',
            'name' => 'Push Mobile',
            'is_enabled' => false,
        ]);
        NotificationEventChannel::create([
            'event_key' => 'cuti.disetujui',
            'notification_channel_id' => $channel->id,
            'is_enabled' => false,
        ]);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/destroy", [])
            ->assertSessionHasErrors(['channel']);

        $this->assertDatabaseHas('ref_notification_channels', ['id' => $channel->id]);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'DELETE',
            'auditable_id' => $channel->id,
        ]);
    }

    public function test_status_global_memakai_desired_state_idempoten_dan_audit_old_new(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/status", ['is_enabled' => false])
            ->assertRedirect();

        $this->assertFalse($channel->refresh()->is_enabled);
        $audit = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefNotificationChannel')
            ->where('auditable_id', $channel->id)
            ->sole();
        $this->assertSame(['is_enabled' => true], $audit->old_values);
        $this->assertSame(['is_enabled' => false], $audit->new_values);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/status", ['is_enabled' => false])
            ->assertRedirect();

        $this->assertFalse($channel->refresh()->is_enabled);
        $this->assertSame(1, AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_id', $channel->id)
            ->count());
    }

    public function test_channel_tanpa_adapter_runtime_tidak_dapat_diaktifkan(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->firstOrFail();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/status", ['is_enabled' => true])
            ->assertSessionHasErrors(['is_enabled']);

        $this->assertFalse($channel->refresh()->is_enabled);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_id' => $channel->id,
        ]);
    }

    public function test_mutasi_status_dibatalkan_saat_audit_gagal(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulasi kegagalan audit channel.');
        });
        $exceptionObserved = false;

        try {
            $this->actingAs($admin)->withoutExceptionHandling()->postWithCsrf(
                "/data-master/channel-notifikasi/{$channel->id}/status",
                ['is_enabled' => false],
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan audit channel.', $exception->getMessage());
            $exceptionObserved = true;
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($exceptionObserved, 'Kegagalan audit wajib diteruskan.');
        $this->assertTrue($channel->refresh()->is_enabled);
    }

    public function test_non_super_admin_ditolak_pada_semua_mutasi_channel(): void
    {
        $adminKepegawaian = User::factory()->adminKepegawaian()->create();
        $channel = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();

        $requests = [
            ['/data-master/channel-notifikasi', ['code' => 'sms', 'name' => 'SMS']],
            ["/data-master/channel-notifikasi/{$channel->id}/update", ['name' => 'Email Baru']],
            ["/data-master/channel-notifikasi/{$channel->id}/status", ['is_enabled' => false]],
            ["/data-master/channel-notifikasi/{$channel->id}/destroy", []],
        ];

        foreach ($requests as [$uri, $payload]) {
            $this->actingAs($adminKepegawaian)
                ->postWithCsrf($uri, $payload)
                ->assertForbidden();
        }

        $this->assertSame('Email', $channel->refresh()->name);
        $this->assertTrue($channel->is_enabled);
        $this->assertDatabaseMissing('ref_notification_channels', ['code' => 'sms']);
    }

    public function test_uuid_channel_malformed_menghasilkan_404_pada_semua_mutasi_bertarget(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach (['update', 'status', 'destroy'] as $operation) {
            $this->actingAs($admin)
                ->postWithCsrf("/data-master/channel-notifikasi/bukan-uuid/{$operation}", [
                    'name' => 'Tidak Relevan',
                    'is_enabled' => false,
                ])
                ->assertNotFound();
        }
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
