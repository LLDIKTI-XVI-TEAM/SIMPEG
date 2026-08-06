<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\NotificationEventChannel;
use App\Models\RefNotificationChannel;
use App\Models\User;
use App\Services\Notifications\NotificationChannelResolver;
use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class NotificationEventChannelManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbac();
    }

    public function test_katalog_memuat_17_event_dan_hanya_adapter_runtime_yang_tersedia(): void
    {
        $catalog = app(NotificationEventCatalog::class);

        $this->assertCount(17, $catalog->events());
        $this->assertTrue($catalog->hasEvent('cuti.ditangguhkan_tugas_dinas'));
        $this->assertTrue($catalog->hasEvent('cuti.dikembalikan_karena_rollover'));
        $this->assertTrue($catalog->hasEvent('cuti.pengajuan_baru'));
        $this->assertTrue($catalog->hasEvent('ews.tidak_perlu'));
        $this->assertTrue($catalog->hasEvent('ews.scheduler_failed'));
        $this->assertTrue($catalog->hasEvent('import_pegawai'));
        $this->assertTrue($catalog->hasEvent('import_pegawai_gagal'));
        $this->assertTrue($catalog->hasAdapter('in_app'));
        $this->assertTrue($catalog->hasAdapter('email'));
        $this->assertFalse($catalog->hasAdapter('whatsapp_business'));
        $this->assertTrue($catalog->supportsChannel('cuti.disetujui', 'email'));
        $this->assertFalse($catalog->supportsChannel('ews.scheduler_failed', 'email'));
    }

    public function test_super_admin_dapat_membuat_dan_memperbarui_policy_supported(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        NotificationEventChannel::query()
            ->where('event_key', 'cuti.disetujui')
            ->where('notification_channel_id', $email->id)
            ->delete();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", [
                'event_key' => 'cuti.disetujui',
                'is_enabled' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $policy = NotificationEventChannel::query()
            ->where('event_key', 'cuti.disetujui')
            ->where('notification_channel_id', $email->id)
            ->sole();
        $this->assertTrue($policy->is_enabled);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", [
                'event_key' => 'cuti.disetujui',
                'is_enabled' => false,
            ])
            ->assertRedirect();

        $this->assertFalse($policy->refresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'CONFIG_UPDATE',
            'auditable_type' => 'NotificationEventChannel',
            'auditable_id' => $policy->id,
        ]);
    }

    public function test_policy_menolak_event_tidak_dikenal_dan_pasangan_tidak_didukung(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", [
                'event_key' => 'event.rekaan',
                'is_enabled' => true,
            ])
            ->assertSessionHasErrors(['event_key']);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", [
                'event_key' => 'ews.scheduler_failed',
                'is_enabled' => true,
            ])
            ->assertSessionHasErrors(['event_key']);

        $this->assertDatabaseMissing('notification_event_channels', [
            'event_key' => 'event.rekaan',
            'notification_channel_id' => $email->id,
        ]);
        $this->assertDatabaseMissing('notification_event_channels', [
            'event_key' => 'ews.scheduler_failed',
            'notification_channel_id' => $email->id,
        ]);
    }

    public function test_perubahan_satu_cell_tidak_mengubah_policy_channel_lain_dan_pasangan_tetap_unik(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $inApp = RefNotificationChannel::query()->where('code', 'in_app')->firstOrFail();
        $inAppPolicy = NotificationEventChannel::query()
            ->where('event_key', 'ews.kgb')
            ->where('notification_channel_id', $inApp->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", [
                'event_key' => 'ews.kgb',
                'is_enabled' => false,
            ])
            ->assertRedirect();
        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", [
                'event_key' => 'ews.kgb',
                'is_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertTrue($inAppPolicy->refresh()->is_enabled);
        $this->assertSame(1, NotificationEventChannel::query()
            ->where('event_key', 'ews.kgb')
            ->where('notification_channel_id', $email->id)
            ->count());
    }

    public function test_resolver_efektif_fail_closed_dan_policy_tetap_tersimpan_saat_master_off_on(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $resolver = app(NotificationChannelResolver::class);

        $this->assertTrue($resolver->isEnabledForEvent('cuti.disetujui', 'email'));
        $this->assertFalse($resolver->isEnabledForEvent('event.tidak_ada', 'email'));

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/status", ['is_enabled' => false])
            ->assertRedirect();

        $this->assertFalse($resolver->isEnabledForEvent('cuti.disetujui', 'email'));
        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'cuti.disetujui',
            'notification_channel_id' => $email->id,
            'is_enabled' => true,
        ]);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/status", ['is_enabled' => true])
            ->assertRedirect();

        $this->assertTrue($resolver->isEnabledForEvent('cuti.disetujui', 'email'));
    }

    public function test_policy_desired_state_idempoten_dan_audit_old_new(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $policy = NotificationEventChannel::query()
            ->where('event_key', 'cuti.disetujui')
            ->where('notification_channel_id', $email->id)
            ->firstOrFail();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", [
                'event_key' => 'cuti.disetujui',
                'is_enabled' => false,
            ])
            ->assertRedirect();

        $audit = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_id', $policy->id)
            ->sole();
        $this->assertSame([
            'event_key' => 'cuti.disetujui',
            'notification_channel_id' => $email->id,
            'is_enabled' => true,
        ], $audit->old_values);
        $this->assertSame([
            'event_key' => 'cuti.disetujui',
            'notification_channel_id' => $email->id,
            'is_enabled' => false,
        ], $audit->new_values);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", [
                'event_key' => 'cuti.disetujui',
                'is_enabled' => false,
            ])
            ->assertRedirect();

        $this->assertSame(1, AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_id', $policy->id)
            ->count());
    }

    public function test_mutasi_policy_dibatalkan_saat_audit_gagal(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $policy = NotificationEventChannel::query()
            ->where('event_key', 'cuti.disetujui')
            ->where('notification_channel_id', $email->id)
            ->firstOrFail();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::creating(function (): void {
            throw new \RuntimeException('Simulasi kegagalan audit policy.');
        });
        $exceptionObserved = false;

        try {
            $this->actingAs($admin)->withoutExceptionHandling()->postWithCsrf(
                "/data-master/channel-notifikasi/{$email->id}/kebijakan-event",
                ['event_key' => 'cuti.disetujui', 'is_enabled' => false],
            );
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulasi kegagalan audit policy.', $exception->getMessage());
            $exceptionObserved = true;
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }

        $this->assertTrue($exceptionObserved, 'Kegagalan audit wajib diteruskan.');
        $this->assertTrue($policy->refresh()->is_enabled);
    }

    public function test_non_super_admin_dan_uuid_malformed_ditolak_pada_mutasi_policy(): void
    {
        $adminKepegawaian = User::factory()->adminKepegawaian()->create();
        $email = RefNotificationChannel::query()->where('code', 'email')->firstOrFail();
        $payload = ['event_key' => 'cuti.disetujui', 'is_enabled' => false];

        $this->actingAs($adminKepegawaian)
            ->postWithCsrf("/data-master/channel-notifikasi/{$email->id}/kebijakan-event", $payload)
            ->assertForbidden();

        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)
            ->postWithCsrf('/data-master/channel-notifikasi/bukan-uuid/kebijakan-event', $payload)
            ->assertNotFound();

        $this->assertDatabaseHas('notification_event_channels', [
            'event_key' => 'cuti.disetujui',
            'notification_channel_id' => $email->id,
            'is_enabled' => true,
        ]);
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        return $this->withSession(['_token' => 'test-token'])
            ->post($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }
}
