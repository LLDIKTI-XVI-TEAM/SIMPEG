<?php

namespace Tests\Feature;

use App\Models\RefNotificationChannel;
use App\Services\Notifications\WhatsApp\QontakWhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppPrivacyGuard;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppRuntimeConfigTest extends TestCase
{
    use RefreshDatabase;

    private const CHANNEL_INTEGRATION_ID = '11111111-2222-4333-8444-555555555555';

    private const ROTATED_CHANNEL_INTEGRATION_ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private WhatsAppRuntimeConfig $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtime = new WhatsAppRuntimeConfig;

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.provider' => 'baseline_provider',
            'services.whatsapp.base_url' => 'https://baseline.example.test',
            'services.whatsapp.canonical_url' => 'https://baseline.example.test',
            'services.whatsapp.access_token' => 'token-dari-secret-manager',
            'services.whatsapp.channel_integration_id' => 'channel-dari-secret-manager',
            'services.whatsapp.event_templates' => ['cuti.disetujui' => 'baseline_template'],
            'services.whatsapp.templates' => ['baseline_template' => ['id' => 'baseline-id']],
        ]);
    }

    private function channelWithConfig(array $config): RefNotificationChannel
    {
        $channel = RefNotificationChannel::query()->where('code', 'whatsapp_business')->first()
            ?? RefNotificationChannel::create(['code' => 'whatsapp_business', 'name' => 'WhatsApp Business', 'is_enabled' => false]);

        $channel->forceFill(['config' => $config])->save();

        return $channel;
    }

    private function qontakMessage(): WhatsAppTemplateMessage
    {
        return new WhatsAppTemplateMessage(
            idempotencyKey: 'snapshot-runtime-1',
            eventKey: 'cuti.disetujui',
            templateKey: 'simpeg_cuti_status',
            templateId: 'template-snapshot-runtime',
            language: 'id',
            recipientAddress: '6281234567890',
            bodyVariables: [
                '1' => 'Ahmad',
                '2' => 'Cuti Tahunan',
                '3' => 'Disetujui',
                '4' => '-',
            ],
            buttonVariables: ['button_target_url' => 'https://simpeg.example.test/cuti/1'],
            recipientName: 'Ahmad',
            variablesMap: [
                'nama_pegawai' => '1',
                'jenis_cuti' => '2',
                'status' => '3',
                'keterangan' => '4',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function qontakProviderConfig(
        string $token,
        ?string $channelIntegrationId,
        string $templateId = 'template-snapshot-runtime',
    ): array {
        return array_filter([
            'provider' => 'qontak',
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            'canonical_url' => 'https://simpeg.example.test',
            'access_token_encrypted' => Crypt::encryptString($token),
            'channel_integration_id' => $channelIntegrationId,
            'template_configuration' => json_encode([
                'event_templates' => ['cuti.disetujui' => 'simpeg_cuti_status'],
                'templates' => [
                    'simpeg_cuti_status' => [
                        'id' => $templateId,
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
                ],
            ], JSON_THROW_ON_ERROR),
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function test_environment_qontak_tidak_dipakai_bila_setting_channel_kosong(): void
    {
        $config = $this->runtime->all();

        $this->assertNull($config['provider']);
        $this->assertNull($config['base_url']);
        $this->assertNull($this->runtime->canonicalUrl());
        $this->assertNull($this->runtime->accessToken());
        $this->assertNull($config['channel_integration_id']);
        $this->assertSame([], $this->runtime->eventTemplates());
    }

    public function test_setting_aplikasi_menjadi_satu_satunya_sumber_artefak_qontak(): void
    {
        $this->channelWithConfig([
            'provider' => 'qontak',
            'base_url' => 'https://service-chat.qontak.com/api/open/v1',
            'channel_integration_id' => self::CHANNEL_INTEGRATION_ID,
            'canonical_url' => 'https://simpeg.lldiktiwil16.id',
            'access_token_encrypted' => Crypt::encryptString('fixture-qontak-token-runtime'),
        ]);

        $config = $this->runtime->all();

        $this->assertSame('qontak', $config['provider']);
        $this->assertSame('https://service-chat.qontak.com/api/open/v1', $config['base_url']);
        $this->assertSame(self::CHANNEL_INTEGRATION_ID, $config['channel_integration_id']);
        $this->assertSame('https://simpeg.lldiktiwil16.id', $config['canonical_url']);
        $this->assertSame('fixture-qontak-token-runtime', $this->runtime->accessToken());
    }

    public function test_channel_integration_id_malformed_di_setting_ditolak_tanpa_fallback_environment(): void
    {
        $this->channelWithConfig(['channel_integration_id' => 'channel-legacy-malformed']);

        $this->assertNull($this->runtime->all()['channel_integration_id']);
    }

    public function test_all_tidak_mengekspos_credential_yang_hanya_boleh_diakses_via_accessor(): void
    {
        $credential = 'fixture-token-runtime-terisolasi';
        $this->channelWithConfig([
            'provider' => 'qontak',
            'access_token_encrypted' => Crypt::encryptString($credential),
        ]);

        $config = $this->runtime->all();

        $this->assertArrayNotHasKey('access_token', $config);
        $this->assertStringNotContainsString(
            $credential,
            json_encode($config, JSON_THROW_ON_ERROR),
        );
        $this->assertSame($credential, $this->runtime->accessToken());
    }

    public function test_kontrak_template_setting_menggantikan_baseline_secara_utuh(): void
    {
        $contract = json_encode([
            'event_templates' => [
                'cuti.disetujui' => 'simpeg16_cuti_status',
                'ews.kgb' => 'simpeg16_ews_pengingat',
            ],
            'templates' => [
                'simpeg16_cuti_status' => [
                    'id' => '505d3ed8-d10c-466f-82d8-94f8365e95d8',
                    'language' => 'id',
                    'variables_map' => ['nama_pegawai' => 'nama_pegawai', 'status' => 'status'],
                    'button' => ['type' => 'url', 'parameter' => '1'],
                ],
                'simpeg16_ews_pengingat' => [
                    'id' => 'f96a20e2-16c6-458c-8810-919614921cfc',
                    'language' => 'id',
                    'variables_map' => ['nama_pegawai' => 'nama_pegawai'],
                    'button' => ['type' => 'url', 'parameter' => '1'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->channelWithConfig(['template_configuration' => $contract]);

        $config = $this->runtime->all();

        $this->assertTrue($config['runtime_configuration_valid']);
        $this->assertSame('simpeg16_cuti_status', $config['event_templates']['cuti.disetujui']);
        $this->assertSame('505d3ed8-d10c-466f-82d8-94f8365e95d8', $this->runtime->template('simpeg16_cuti_status')['id']);
        $this->assertArrayNotHasKey('baseline_template', $config['templates']);
    }

    public function test_kontrak_setting_tidak_valid_mematikan_seluruh_template(): void
    {
        $this->channelWithConfig(['template_configuration' => '{json-rusak']);

        $config = $this->runtime->all();

        $this->assertFalse($config['runtime_configuration_valid']);
        $this->assertSame([], $config['event_templates']);
        $this->assertSame([], $config['templates']);
    }

    public function test_runtime_mengarantina_kontrak_legacy_yang_memuat_credential(): void
    {
        $credential = 'fixture-credential-runtime-legacy';
        $contract = json_encode([
            'event_templates' => ['cuti.disetujui' => 'simpeg16_cuti_status'],
            'templates' => [
                'simpeg16_cuti_status' => [
                    'id' => 'template-legacy',
                    'metadata' => ['secret' => $credential],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $this->channelWithConfig(['template_configuration' => $contract]);

        $config = $this->runtime->all();

        $this->assertNull($config['template_configuration']);
        $this->assertFalse($config['runtime_configuration_valid']);
        $this->assertSame([], $config['event_templates']);
        $this->assertSame([], $config['templates']);
        $this->assertStringNotContainsString(
            $credential,
            json_encode($config, JSON_THROW_ON_ERROR),
        );
    }

    public function test_kontrak_dalam_bentuk_array_juga_diterima(): void
    {
        $this->channelWithConfig([
            'template_configuration' => [
                'event_templates' => ['cuti.disetujui' => 'simpeg16_cuti_status'],
                'templates' => [
                    'simpeg16_cuti_status' => [
                        'id' => '505d3ed8-d10c-466f-82d8-94f8365e95d8',
                        'language' => 'id',
                        'variables_map' => ['nama_pegawai' => 'nama_pegawai'],
                        'button' => ['type' => 'url', 'parameter' => '1'],
                    ],
                ],
            ],
        ]);

        $config = $this->runtime->all();

        $this->assertTrue($config['runtime_configuration_valid']);
        $this->assertSame('simpeg16_cuti_status', $config['event_templates']['cuti.disetujui']);
    }

    public function test_token_plaintext_legacy_di_setting_ditolak_tanpa_fallback_environment(): void
    {
        $this->channelWithConfig(['access_token' => 'bukan-cipher-valid']);

        $this->assertNull($this->runtime->accessToken());
    }

    public function test_ciphertext_malformed_menghasilkan_null_tanpa_exception_keluar(): void
    {
        $this->channelWithConfig(['access_token_encrypted' => 'ciphertext-malformed-sintetis']);

        $this->assertNull($this->runtime->accessToken());
    }

    public function test_field_yang_dihapus_dari_setting_tidak_jatuh_kembali_ke_baseline(): void
    {
        // Baris setting terisi tetapi base_url/canonical_url tidak ada: baseline env
        // tidak boleh menghidupkan kembali nilai yang sudah dihapus operator.
        $this->channelWithConfig(['template_configuration' => null]);

        $config = $this->runtime->all();

        $this->assertNull($config['provider']);
        $this->assertNull($config['base_url']);
        $this->assertNull($config['canonical_url']);
        $this->assertNull($config['channel_integration_id']);
    }

    public function test_kontrak_yang_dihapus_dari_setting_tidak_digantikan_kontrak_baseline(): void
    {
        $this->channelWithConfig(['base_url' => 'https://service-chat.qontak.com/api/open/v1']);

        $config = $this->runtime->all();

        $this->assertNull($config['template_configuration']);
        $this->assertFalse($config['runtime_configuration_valid']);
        $this->assertSame([], $config['event_templates']);
        $this->assertSame([], $config['templates']);
    }

    public function test_invalidate_membuang_memo_sehingga_perubahan_setting_langsung_terbaca(): void
    {
        $this->channelWithConfig(['canonical_url' => 'https://lama.example.test']);
        $this->assertSame('https://lama.example.test', $this->runtime->all()['canonical_url']);

        // Perubahan di dalam jendela TTL belum terlihat karena memo masih hidup.
        $this->channelWithConfig(['canonical_url' => 'https://baru.example.test']);
        $this->assertSame('https://lama.example.test', $this->runtime->all()['canonical_url']);

        $this->runtime->invalidate();

        $this->assertSame('https://baru.example.test', $this->runtime->all()['canonical_url']);
    }

    public function test_credential_tidak_ikut_ttl_dan_rotasi_serta_clear_langsung_terbaca_worker(): void
    {
        $this->channelWithConfig([
            'access_token_encrypted' => Crypt::encryptString('fixture-token-worker-lama'),
        ]);
        $this->assertSame('fixture-token-worker-lama', $this->runtime->accessToken());

        $this->channelWithConfig([
            'access_token_encrypted' => Crypt::encryptString('fixture-token-worker-baru'),
        ]);
        $this->assertSame('fixture-token-worker-baru', $this->runtime->accessToken());

        $this->channelWithConfig(['configuration_override' => true]);
        $this->assertNull($this->runtime->accessToken());
        $this->assertNull((new WhatsAppRuntimeConfig)->accessToken());
    }

    public function test_adapter_memakai_token_dan_channel_id_dari_snapshot_rotasi_yang_sama(): void
    {
        $this->channelWithConfig($this->qontakProviderConfig(
            'fixture-token-worker-lama',
            self::CHANNEL_INTEGRATION_ID,
        ));
        $this->runtime->all();

        $this->channelWithConfig($this->qontakProviderConfig(
            'fixture-token-worker-baru',
            self::ROTATED_CHANNEL_INTEGRATION_ID,
        ));
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = (new QontakWhatsAppTemplateAdapter($this->runtime, new WhatsAppPrivacyGuard))
            ->send($this->qontakMessage());

        $this->assertTrue($result->delivered);
        Http::assertSent(fn ($request): bool => $request->hasHeader(
            'Authorization',
            'Bearer fixture-token-worker-baru',
        ) && $request->data()['channel_integration_id'] === self::ROTATED_CHANNEL_INTEGRATION_ID);
    }

    public function test_adapter_menolak_payload_lama_saat_konfigurasi_dan_kredensial_dirotasi_atomik(): void
    {
        $this->channelWithConfig($this->qontakProviderConfig(
            'fixture-token-worker-lama',
            self::CHANNEL_INTEGRATION_ID,
        ));
        $this->runtime->all();

        $this->channelWithConfig($this->qontakProviderConfig(
            'fixture-token-worker-baru',
            self::ROTATED_CHANNEL_INTEGRATION_ID,
            'template-snapshot-runtime-baru',
        ));
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = (new QontakWhatsAppTemplateAdapter($this->runtime, new WhatsAppPrivacyGuard))
            ->send($this->qontakMessage());

        $this->assertFalse($result->delivered);
        $this->assertSame('delivery_rejected', $result->code);
        $this->assertSame(
            'template-snapshot-runtime-baru',
            $this->runtime->template('simpeg_cuti_status')['id'],
        );
        Http::assertNothingSent();
    }

    public function test_adapter_gagal_tutup_saat_channel_id_dihapus_di_dalam_ttl(): void
    {
        $this->channelWithConfig($this->qontakProviderConfig(
            'fixture-token-worker',
            self::CHANNEL_INTEGRATION_ID,
        ));
        $this->runtime->all();

        $this->channelWithConfig($this->qontakProviderConfig('fixture-token-worker', null));
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = (new QontakWhatsAppTemplateAdapter($this->runtime, new WhatsAppPrivacyGuard))
            ->send($this->qontakMessage());

        $this->assertFalse($result->delivered);
        $this->assertSame('provider_misconfigured', $result->code);
        Http::assertNothingSent();
    }
}
