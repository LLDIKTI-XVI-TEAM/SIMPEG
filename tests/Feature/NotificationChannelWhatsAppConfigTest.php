<?php

namespace Tests\Feature;

use App\Actions\Notifications\SaveWhatsAppChannelConfigAction;
use App\Models\AuditLog;
use App\Models\RefNotificationChannel;
use App\Models\User;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationChannelWhatsAppConfigTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL_INTEGRATION_ID = '11111111-2222-4333-8444-555555555555';

    private const CHANNEL_INTEGRATION_ID_ROTATED = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRbac();
    }

    private function whatsappChannel(): RefNotificationChannel
    {
        return RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => false]);
    }

    private function postWithCsrf(string $uri, array $data): TestResponse
    {
        $data['_token'] = csrf_token();

        return $this->post($uri, $data);
    }

    /** @return array<string, mixed> */
    private function rawWhatsAppConfig(string $channelId): array
    {
        $rawConfig = DB::table('ref_notification_channels')->where('id', $channelId)->value('config');

        return is_string($rawConfig)
            ? json_decode($rawConfig, true, flags: JSON_THROW_ON_ERROR)
            : $rawConfig;
    }

    public function test_super_admin_dapat_menyimpan_konfigurasi_non_rahasia_tanpa_credential_atau_channel_id_di_database(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'base_url' => 'https://service-chat.qontak.com/api/open/v1',
                'canonical_url' => 'https://simpeg.lldiktiwil16.id',
                'template_configuration' => json_encode([
                    'event_templates' => ['cuti.disetujui' => 'simpeg16_cuti_status'],
                    'templates' => [
                        'simpeg16_cuti_status' => [
                            'id' => '505d3ed8-d10c-466f-82d8-94f8365e95d8',
                            'language' => 'id',
                            'variables_map' => ['nama_pegawai' => 'nama_pegawai'],
                            'button' => ['type' => 'url', 'parameter' => '1'],
                        ],
                    ],
                ]),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $channel->refresh();

        $this->assertSame('qontak', $channel->config['provider']);
        $this->assertSame('https://service-chat.qontak.com/api/open/v1', $channel->config['base_url']);
        $this->assertSame('https://simpeg.lldiktiwil16.id', $channel->config['canonical_url']);

        $this->assertArrayNotHasKey('access_token', $channel->config);
        $this->assertArrayNotHasKey('channel_integration_id', $channel->config);
    }

    public function test_canonical_url_dengan_path_atau_host_loopback_ditolak_tanpa_mutasi(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        foreach ([
            'https://simpeg.example.test/subpath',
            'https://localhost',
            'https://127.0.0.1',
            'https://[::1]',
        ] as $canonicalUrl) {
            $this->actingAs($admin)
                ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                    'canonical_url' => $canonicalUrl,
                ])
                ->assertSessionHasErrors('canonical_url');
        }

        $this->assertNull($channel->fresh()->config);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_mengosongkan_seluruh_form_menimpa_baseline_environment_secara_fail_closed(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $channel->forceFill(['config' => null])->save();

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => 'qontak',
            'services.whatsapp.base_url' => 'https://service-chat.qontak.com/api/open/v1',
            'services.whatsapp.access_token' => 'credential-dari-environment',
            'services.whatsapp.channel_integration_id' => 'channel-dari-environment',
            'services.whatsapp.template_configuration' => 'kontrak-dari-environment',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
        ]);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'base_url' => '',
                'canonical_url' => '',
                'template_configuration' => '',
            ])
            ->assertRedirect();

        $runtime = (new WhatsAppRuntimeConfig)->all();

        $this->assertNull($runtime['base_url']);
        $this->assertNull($runtime['canonical_url']);
        $this->assertNull($runtime['template_configuration']);
        $this->assertFalse($runtime['runtime_configuration_valid']);
    }

    public function test_audit_mencatat_config_update_tanpa_credential(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            ])
            ->assertRedirect();

        $audit = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefNotificationChannel')
            ->where('auditable_id', $channel->id)
            ->latest()
            ->firstOrFail();

        $newValues = $audit->new_values;

        $this->assertSame('https://service-chat.qontak.com/api/open/v1', $newValues['base_url']);
        $this->assertArrayNotHasKey('access_token', $newValues);
        $this->assertArrayNotHasKey('channel_integration_id', $newValues);
    }

    public function test_access_token_disimpan_terenkripsi_dan_hanya_tersedia_pada_runtime(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $token = 'fixture-qontak-access-token-baru';

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'base_url' => 'https://service-chat.qontak.com/api/open/v1',
                'access_token' => $token,
            ])
            ->assertRedirect();

        $storedConfig = $this->rawWhatsAppConfig($channel->id);
        $ciphertext = $storedConfig['access_token_encrypted'] ?? null;

        $this->assertIsString($ciphertext);
        $this->assertNotSame($token, $ciphertext);
        $this->assertStringNotContainsString($token, json_encode($storedConfig, JSON_THROW_ON_ERROR));
        $this->assertSame($token, (new WhatsAppRuntimeConfig)->accessToken());

        $response = $this->actingAs($admin)->get('/data-master/channel-notifikasi')->assertOk();
        $response
            ->assertSee('Token akses tersimpan')
            ->assertSee('name="access_token"', false)
            ->assertSee('name="clear_access_token"', false)
            ->assertDontSee($token)
            ->assertDontSee($ciphertext);

        $serializedChannel = json_encode($channel->fresh()->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($token, $serializedChannel);
        $this->assertStringNotContainsString($ciphertext, $serializedChannel);

        $audit = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_id', $channel->id)
            ->latest()
            ->firstOrFail();
        $serializedAudit = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);

        $this->assertSame('added', $audit->new_values['access_token_status'] ?? null);
        $this->assertStringNotContainsString($token, $serializedAudit);
        $this->assertStringNotContainsString($ciphertext, $serializedAudit);
    }

    public function test_access_token_kosong_mempertahankan_ciphertext_lama(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['access_token' => 'fixture-qontak-token-tetap'],
        )->assertRedirect();
        $ciphertextLama = $this->rawWhatsAppConfig($channel->id)['access_token_encrypted'];
        $auditAwalId = AuditLog::query()->sole()->id;

        $this->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['access_token' => '', 'canonical_url' => 'https://simpeg.example.test'],
        )->assertRedirect();

        $this->assertSame($ciphertextLama, $this->rawWhatsAppConfig($channel->id)['access_token_encrypted']);
        $this->assertSame('fixture-qontak-token-tetap', (new WhatsAppRuntimeConfig)->accessToken());
        $this->assertSame('unchanged', AuditLog::query()->whereKeyNot($auditAwalId)->sole()->new_values['access_token_status']);
    }

    public function test_ciphertext_access_token_rusak_ditampilkan_sebagai_belum_terkonfigurasi(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $channel->forceFill(['config' => [
            'access_token_encrypted' => 'fixture-ciphertext-rusak',
        ]])->save();

        $this->actingAs($admin)
            ->get('/data-master/channel-notifikasi')
            ->assertOk()
            ->assertSee('Token akses belum tersimpan')
            ->assertSee('placeholder="Masukkan access token Qontak"', false)
            ->assertDontSee('fixture-ciphertext-rusak');
    }

    public function test_access_token_baru_merotasi_ciphertext(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['access_token' => 'fixture-qontak-token-lama'],
        )->assertRedirect();
        $ciphertextLama = $this->rawWhatsAppConfig($channel->id)['access_token_encrypted'];
        $auditAwalId = AuditLog::query()->sole()->id;

        $this->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['access_token' => 'fixture-qontak-token-rotasi'],
        )->assertRedirect();
        $ciphertextBaru = $this->rawWhatsAppConfig($channel->id)['access_token_encrypted'];

        $this->assertNotSame($ciphertextLama, $ciphertextBaru);
        $this->assertSame('fixture-qontak-token-rotasi', (new WhatsAppRuntimeConfig)->accessToken());
        $this->assertSame('rotated', AuditLog::query()->whereKeyNot($auditAwalId)->sole()->new_values['access_token_status']);
    }

    public function test_clear_access_token_menghapus_ciphertext_secara_eksplisit(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['access_token' => 'fixture-qontak-token-hapus'],
        )->assertRedirect();
        $auditAwalId = AuditLog::query()->sole()->id;

        $this->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['clear_access_token' => '1'],
        )->assertRedirect();

        $this->assertArrayNotHasKey('access_token_encrypted', $this->rawWhatsAppConfig($channel->id));
        $this->assertNull((new WhatsAppRuntimeConfig)->accessToken());
        $this->assertSame('cleared', AuditLog::query()->whereKeyNot($auditAwalId)->sole()->new_values['access_token_status']);
    }

    public function test_access_token_baru_dan_clear_bersamaan_ditolak_tanpa_mutasi(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['access_token' => 'fixture-qontak-token-awal'],
        )->assertRedirect();
        $ciphertextLama = $this->rawWhatsAppConfig($channel->id)['access_token_encrypted'];

        $this->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['access_token' => 'fixture-qontak-token-tidak-boleh-masuk', 'clear_access_token' => '1'],
        )->assertSessionHasErrors('access_token');

        $serializedSession = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('fixture-qontak-token-tidak-boleh-masuk', $serializedSession);
        $this->assertSame($ciphertextLama, $this->rawWhatsAppConfig($channel->id)['access_token_encrypted']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_field_credential_nested_ditolak_sebelum_konfigurasi_dan_audit_disimpan(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        foreach ([
            'access_token',
            'refresh_token',
            'token',
            'channel_integration_id',
            'client_secret',
            'secret',
            'api_key',
            'authorization',
            'password',
        ] as $sensitiveKey) {
            $this->actingAs($admin)
                ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                    'base_url' => 'https://service-chat.qontak.com/api/open/v1',
                    'metadata' => ['nested' => [strtoupper($sensitiveKey) => "rahasia-{$sensitiveKey}"]],
                ])
                ->assertSessionHasErrors('configuration');
        }

        $this->assertNull($this->whatsappChannel()->config);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_refresh_token_tetap_ditolak_dan_channel_id_tidak_diflash_ke_session(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'refresh_token' => 'fixture-refresh-token-terlarang',
                'channel_integration_id' => self::CHANNEL_INTEGRATION_ID,
            ])
            ->assertSessionHasErrors(['refresh_token']);

        $serializedSession = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('fixture-refresh-token-terlarang', $serializedSession);
        $this->assertStringNotContainsString(self::CHANNEL_INTEGRATION_ID, $serializedSession);
        $this->assertNull($this->whatsappChannel()->config);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_channel_integration_id_disimpan_write_only_dan_tersedia_pada_runtime(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'channel_integration_id' => self::CHANNEL_INTEGRATION_ID,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $stored = $this->rawWhatsAppConfig($channel->id);
        $this->assertSame(self::CHANNEL_INTEGRATION_ID, $stored['channel_integration_id']);
        $this->assertSame(
            self::CHANNEL_INTEGRATION_ID,
            (new WhatsAppRuntimeConfig)->all()['channel_integration_id'],
        );

        $response = $this->get('/data-master/channel-notifikasi')->assertOk();
        $response
            ->assertSee('Channel ID tersimpan')
            ->assertSee('name="channel_integration_id"', false)
            ->assertSee('name="clear_channel_integration_id"', false)
            ->assertDontSee(self::CHANNEL_INTEGRATION_ID);

        $this->assertStringNotContainsString(
            self::CHANNEL_INTEGRATION_ID,
            json_encode($channel->fresh()->toArray(), JSON_THROW_ON_ERROR),
        );

        $audit = AuditLog::query()->where('event', 'CONFIG_UPDATE')->latest()->firstOrFail();
        $this->assertSame('added', $audit->new_values['channel_integration_id_status'] ?? null);
        $this->assertStringNotContainsString(
            self::CHANNEL_INTEGRATION_ID,
            json_encode($audit->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_channel_integration_id_kosong_mempertahankan_rotasi_dan_clear_eksplisit(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $this->actingAs($admin);

        $this->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['channel_integration_id' => self::CHANNEL_INTEGRATION_ID],
        )->assertRedirect();

        $this->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['channel_integration_id' => '', 'canonical_url' => 'https://simpeg.example.test'],
        )->assertRedirect();
        $this->assertSame(self::CHANNEL_INTEGRATION_ID, $this->rawWhatsAppConfig($channel->id)['channel_integration_id']);
        $this->assertSame('unchanged', AuditLog::query()->latest()->firstOrFail()->new_values['channel_integration_id_status']);

        $this->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['channel_integration_id' => self::CHANNEL_INTEGRATION_ID_ROTATED],
        )->assertRedirect();
        $this->assertSame(self::CHANNEL_INTEGRATION_ID_ROTATED, $this->rawWhatsAppConfig($channel->id)['channel_integration_id']);
        $this->assertSame('rotated', AuditLog::query()->latest()->firstOrFail()->new_values['channel_integration_id_status']);

        $this->postWithCsrf(
            "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
            ['clear_channel_integration_id' => '1'],
        )->assertRedirect();
        $this->assertArrayNotHasKey('channel_integration_id', $this->rawWhatsAppConfig($channel->id));
        $this->assertNull((new WhatsAppRuntimeConfig)->all()['channel_integration_id']);
        $this->assertSame('cleared', AuditLog::query()->latest()->firstOrFail()->new_values['channel_integration_id_status']);
    }

    public function test_channel_integration_id_invalid_atau_bersamaan_clear_ditolak_tanpa_mutasi(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $this->actingAs($admin);

        foreach ([
            ['channel_integration_id' => 'bukan-uuid'],
            [
                'channel_integration_id' => self::CHANNEL_INTEGRATION_ID,
                'clear_channel_integration_id' => '1',
            ],
        ] as $payload) {
            $this->postWithCsrf(
                "/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp",
                $payload,
            )->assertSessionHasErrors('channel_integration_id');

            $serializedSession = json_encode(session()->all(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString((string) $payload['channel_integration_id'], $serializedSession);
        }

        $this->assertNull($channel->fresh()->config);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_simpan_konfigurasi_membersihkan_credential_legacy_dan_audit_tidak_membocorkannya(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $channel->forceFill(['config' => [
            'provider' => 'qontak',
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            'access_token' => 'legacy-secret-token',
            'refresh_token' => 'legacy-refresh-token',
            'channel_integration_id' => 'legacy-channel-id',
            'consumer_secret' => 'legacy-consumer-secret',
        ]])->save();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            ])
            ->assertRedirect();

        $channel->refresh();

        $this->assertSame('https://service-chat.qontak.com/api/open/v1', $channel->config['base_url']);
        $this->assertArrayNotHasKey('access_token', $channel->config);
        $this->assertArrayNotHasKey('refresh_token', $channel->config);
        $this->assertArrayNotHasKey('channel_integration_id', $channel->config);
        $this->assertArrayNotHasKey('consumer_secret', $channel->config);

        $audit = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefNotificationChannel')
            ->where('auditable_id', $channel->id)
            ->latest()
            ->firstOrFail();

        $serializedAudit = json_encode([
            'old_values' => $audit->old_values,
            'new_values' => $audit->new_values,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('legacy-secret-token', $serializedAudit);
        $this->assertStringNotContainsString('legacy-refresh-token', $serializedAudit);
        $this->assertStringNotContainsString('legacy-channel-id', $serializedAudit);
        $this->assertStringNotContainsString('legacy-consumer-secret', $serializedAudit);
    }

    public function test_simpan_ulang_konfigurasi_legacy_membersihkan_credential_nested_dari_database_dan_audit(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $channel->forceFill(['config' => [
            'provider' => 'qontak',
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            'legacy' => [
                'Access_Token' => 'legacy-nested-access-token',
                'child' => ['PASSWORD' => 'legacy-nested-password'],
            ],
            'encoded_legacy' => json_encode([
                'label' => 'metadata lama',
                'authorization' => 'legacy-json-authorization',
            ], JSON_THROW_ON_ERROR),
        ]])->save();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            ])
            ->assertRedirect();

        $channel->refresh();
        $serializedConfig = json_encode($channel->config, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('legacy-nested-access-token', $serializedConfig);
        $this->assertStringNotContainsString('legacy-nested-password', $serializedConfig);
        $this->assertStringNotContainsString('legacy-json-authorization', $serializedConfig);
        $this->assertArrayNotHasKey('encoded_legacy', $this->rawWhatsAppConfig($channel->id));

        $audit = AuditLog::query()
            ->where('event', 'CONFIG_UPDATE')
            ->where('auditable_type', 'RefNotificationChannel')
            ->where('auditable_id', $channel->id)
            ->latest()
            ->firstOrFail();

        $serializedAudit = json_encode([
            'old_values' => $audit->old_values,
            'new_values' => $audit->new_values,
        ], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('legacy-nested-access-token', $serializedAudit);
        $this->assertStringNotContainsString('legacy-nested-password', $serializedAudit);
        $this->assertStringNotContainsString('legacy-json-authorization', $serializedAudit);
    }

    public function test_kontrak_template_tidak_valid_ditolak_tanpa_menyimpan(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'template_configuration' => '{json-rusak',
            ])
            ->assertSessionHasErrors(['template_configuration']);

        $this->assertNull($this->whatsappChannel()->config);
    }

    public function test_action_direct_menolak_kontrak_invalid_tanpa_mutasi_atau_audit(): void
    {
        $channel = $this->whatsappChannel();
        $channel->forceFill(['config' => [
            'provider' => 'qontak',
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
        ]])->save();
        $configAwal = $this->rawWhatsAppConfig($channel->id);
        $exception = null;

        try {
            app(SaveWhatsAppChannelConfigAction::class)->execute(
                $channel->id,
                ['template_configuration' => '{json-rusak-direct-action'],
                Request::create('/internal/konfigurasi-whatsapp', 'POST'),
            );
        } catch (ValidationException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertArrayHasKey('template_configuration', $exception->errors());
        $this->assertSame($configAwal, $this->rawWhatsAppConfig($channel->id));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_action_direct_menolak_channel_integration_id_invalid_tanpa_mutasi_atau_audit(): void
    {
        $channel = $this->whatsappChannel();
        $exception = null;

        try {
            app(SaveWhatsAppChannelConfigAction::class)->execute(
                $channel->id,
                ['channel_integration_id' => 'bukan-uuid'],
                Request::create('/internal/konfigurasi-whatsapp', 'POST'),
            );
        } catch (ValidationException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertArrayHasKey('channel_integration_id', $exception->errors());
        $this->assertNull($channel->fresh()->config);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_action_direct_menolak_field_button_tidak_dikenal_tanpa_mutasi_atau_audit(): void
    {
        $channel = $this->whatsappChannel();
        $contract = json_encode([
            'event_templates' => ['cuti.disetujui' => 'simpeg16_cuti_status'],
            'templates' => [
                'simpeg16_cuti_status' => [
                    'id' => 'template-direct',
                    'language' => 'id',
                    'variables_map' => [],
                    'button' => [
                        'type' => 'url',
                        'parameter' => 'cta_url',
                        'providerSecret' => 'fixture-credential-direct-action',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $exception = null;

        try {
            app(SaveWhatsAppChannelConfigAction::class)->execute(
                $channel->id,
                ['template_configuration' => $contract],
                Request::create('/internal/konfigurasi-whatsapp', 'POST'),
            );
        } catch (ValidationException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertArrayHasKey('template_configuration', $exception->errors());
        $this->assertNull(RefNotificationChannel::query()->findOrFail($channel->id)->config);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('providerCredentialAliases')]
    public function test_kontrak_template_dengan_alias_credential_ditolak_tanpa_kebocoran(string $credentialKey): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $credential = 'fixture-credential-json-terlarang';
        $contract = json_encode([
            'event_templates' => ['cuti.disetujui' => 'simpeg16_cuti_status'],
            'templates' => [
                'simpeg16_cuti_status' => [
                    'id' => 'template-aman',
                    'variables_map' => [$credentialKey => $credential],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'template_configuration' => $contract,
            ])
            ->assertSessionHasErrors('template_configuration');

        $serializedSession = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($credential, $serializedSession);
        $this->assertArrayNotHasKey('template_configuration', session('_old_input', []));
        $this->assertNull(RefNotificationChannel::query()->findOrFail($channel->id)->config);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->get('/data-master/channel-notifikasi')
            ->assertOk()
            ->assertDontSee($credential);
    }

    /** @return array<string, array{string}> */
    public static function providerCredentialAliases(): array
    {
        return [
            'camel case' => ['accessToken'],
            'app secret camel case' => ['appSecret'],
            'header api key' => ['X-Api-Key'],
            'bearer token' => ['bearerToken'],
            'private key camel case' => ['privateKey'],
            'consumer secret' => ['consumer_secret'],
            'oauth token' => ['oauth_token'],
            'private token camel case' => ['privateToken'],
            'provider secret camel case' => ['providerSecret'],
        ];
    }

    public function test_kontrak_legacy_dengan_credential_dikarantina_dari_render_database_dan_audit(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $credential = 'fixture-credential-json-legacy';
        $contract = json_encode([
            'event_templates' => ['cuti.disetujui' => 'simpeg16_cuti_status'],
            'templates' => [
                'simpeg16_cuti_status' => [
                    'id' => 'template-legacy',
                    'metadata' => ['refresh_token' => $credential],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $channel->forceFill(['config' => [
            'provider' => 'qontak',
            'template_configuration' => $contract,
        ]])->save();

        $this->actingAs($admin)
            ->get('/data-master/channel-notifikasi')
            ->assertOk()
            ->assertDontSee($credential);
        $this->assertStringNotContainsString(
            $credential,
            json_encode($channel->fresh()->toArray(), JSON_THROW_ON_ERROR),
        );

        $this->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
        ])->assertRedirect();

        $stored = $this->rawWhatsAppConfig($channel->id);
        $this->assertArrayNotHasKey('template_configuration', $stored);
        $audit = AuditLog::query()->latest()->firstOrFail();
        $this->assertStringNotContainsString(
            $credential,
            json_encode([$audit->old_values, $audit->new_values], JSON_THROW_ON_ERROR),
        );
    }

    public function test_token_baru_dengan_kontrak_invalid_tidak_diflash_ke_session_atau_html(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $credential = 'fixture-token-kontrak-invalid';

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'access_token' => $credential,
                'template_configuration' => '{json-rusak',
            ])
            ->assertSessionHasErrors('template_configuration');

        $this->assertStringNotContainsString(
            $credential,
            json_encode(session()->all(), JSON_THROW_ON_ERROR),
        );
        $this->assertArrayNotHasKey('access_token', session('_old_input', []));
        $this->assertNull(RefNotificationChannel::query()->findOrFail($channel->id)->config);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->get('/data-master/channel-notifikasi')
            ->assertOk()
            ->assertDontSee($credential);
    }

    public function test_kontrak_unverifiable_dihapus_dari_old_input_dan_html(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $fixtures = [
            'fixture-credential-malformed' => '{"templates":{"legacy":{"secret":"fixture-credential-malformed"}}',
            'fixture-credential-double-encoded' => json_encode(
                json_encode([
                    'templates' => [
                        'legacy' => ['refresh_token' => 'fixture-credential-double-encoded'],
                    ],
                ], JSON_THROW_ON_ERROR),
                JSON_THROW_ON_ERROR,
            ),
            'fixture-credential-json-string' => json_encode([
                'event_templates' => [],
                'templates' => [],
                'metadata' => json_encode([
                    'refresh_token' => 'fixture-credential-json-string',
                ], JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR),
            'fixture-credential-nested-double-encoded' => json_encode([
                'event_templates' => [],
                'templates' => [],
                'metadata' => json_encode(
                    json_encode([
                        'appSecret' => 'fixture-credential-nested-double-encoded',
                    ], JSON_THROW_ON_ERROR),
                    JSON_THROW_ON_ERROR,
                ),
            ], JSON_THROW_ON_ERROR),
        ];

        $this->actingAs($admin);

        foreach ($fixtures as $credential => $contract) {
            $this->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'template_configuration' => $contract,
            ])->assertSessionHasErrors('template_configuration');

            $this->assertStringNotContainsString(
                $credential,
                json_encode(session()->all(), JSON_THROW_ON_ERROR),
            );
            $this->assertArrayNotHasKey('template_configuration', session('_old_input', []));
            $this->get('/data-master/channel-notifikasi')
                ->assertOk()
                ->assertDontSee($credential);
        }

        $this->assertNull(RefNotificationChannel::query()->findOrFail($channel->id)->config);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_kontrak_legacy_unverifiable_dikarantina_lintas_surface(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $fixtures = [
            'fixture-legacy-malformed' => '{"templates":{"legacy":{"api_key":"fixture-legacy-malformed"}}',
            'fixture-legacy-double-encoded' => json_encode(
                json_encode([
                    'templates' => [
                        'legacy' => ['authorization' => 'fixture-legacy-double-encoded'],
                    ],
                ], JSON_THROW_ON_ERROR),
                JSON_THROW_ON_ERROR,
            ),
            'fixture-legacy-json-string' => json_encode([
                'event_templates' => [],
                'templates' => [],
                'metadata' => json_encode([
                    'authorization' => 'fixture-legacy-json-string',
                ], JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR),
            'fixture-legacy-unknown-schema' => json_encode([
                'event_templates' => [],
                'templates' => [],
                'metadata' => [
                    'consumer_secret_v2' => 'fixture-legacy-unknown-schema',
                ],
            ], JSON_THROW_ON_ERROR),
        ];

        $this->actingAs($admin);

        foreach ($fixtures as $credential => $contract) {
            $channel->forceFill(['config' => [
                'provider' => 'qontak',
                'template_configuration' => $contract,
            ]])->save();

            $this->get('/data-master/channel-notifikasi')
                ->assertOk()
                ->assertDontSee($credential);
            $this->assertStringNotContainsString(
                $credential,
                json_encode($channel->fresh()->toArray(), JSON_THROW_ON_ERROR),
            );
            $this->assertStringNotContainsString(
                $credential,
                json_encode((new WhatsAppRuntimeConfig)->all(), JSON_THROW_ON_ERROR),
            );

            $this->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            ])->assertRedirect();

            $this->assertArrayNotHasKey(
                'template_configuration',
                $this->rawWhatsAppConfig($channel->id),
            );
            $audit = AuditLog::query()->latest()->firstOrFail();
            $this->assertStringNotContainsString(
                $credential,
                json_encode([$audit->old_values, $audit->new_values], JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_form_menampilkan_kontrak_berbentuk_array_sebagai_json(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        $channel->forceFill(['config' => [
            'template_configuration' => [
                'event_templates' => ['cuti.disetujui' => 'simpeg16_cuti_status'],
                'templates' => ['simpeg16_cuti_status' => ['id' => 'template-1']],
            ],
        ]])->save();

        $this->actingAs($admin)
            ->get('/data-master/channel-notifikasi')
            ->assertOk()
            ->assertSee('simpeg16_cuti_status');
    }

    public function test_form_mengarantina_dan_memulihkan_konfigurasi_json_scalar_tanpa_error(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();
        DB::table('ref_notification_channels')->where('id', $channel->id)->update([
            'config' => json_encode('legacy-scalar', JSON_THROW_ON_ERROR),
        ]);

        $this->actingAs($admin)
            ->get('/data-master/channel-notifikasi')
            ->assertOk()
            ->assertDontSee('legacy-scalar');

        $this->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
        ])->assertRedirect();

        $stored = $this->rawWhatsAppConfig($channel->id);
        $this->assertSame('qontak', $stored['provider']);
        $this->assertSame('https://service-chat.qontak.com/api/open/v1', $stored['base_url']);
        $audit = AuditLog::query()->latest()->firstOrFail();
        $this->assertStringNotContainsString(
            'legacy-scalar',
            json_encode([$audit->old_values, $audit->new_values], JSON_THROW_ON_ERROR),
        );
    }

    public function test_base_url_di_luar_endpoint_qontak_resmi_ditolak(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $channel = $this->whatsappChannel();

        foreach ([
            'http://service-chat.qontak.com/api/open/v1',
            'https://evil.example.com/api/open/v1',
            'https://service-chat.qontak.com.evil.example/api/open/v1',
            'https://127.0.0.1/api/open/v1',
        ] as $baseUrl) {
            $this->actingAs($admin)
                ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                    'base_url' => $baseUrl,
                ])
                ->assertSessionHasErrors(['base_url']);
        }

        $this->assertNull($this->whatsappChannel()->config);
    }

    public function test_konfigurasi_tidak_berlaku_untuk_channel_selain_whatsapp(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $emailChannel = RefNotificationChannel::query()->where('code', 'email')->first();

        $this->actingAs($admin)
            ->postWithCsrf("/data-master/channel-notifikasi/{$emailChannel->id}/konfigurasi-whatsapp", [
                'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            ])
            ->assertForbidden();
    }

    public function test_non_super_admin_ditolak(): void
    {
        $user = User::factory()->create();
        $channel = $this->whatsappChannel();

        $this->actingAs($user)
            ->postWithCsrf("/data-master/channel-notifikasi/{$channel->id}/konfigurasi-whatsapp", [
                'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            ])
            ->assertForbidden();

        $this->assertNull($this->whatsappChannel()->config);
    }
}
