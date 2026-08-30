<?php

namespace Tests\Unit;

use App\Services\Notifications\WhatsApp\QontakWhatsAppProviderContract;
use App\Services\Notifications\WhatsApp\WhatsAppReadiness;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfiguration;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateConfiguration;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;
use Tests\TestCase;

class WhatsAppReadinessTest extends TestCase
{
    private WhatsAppReadiness $readiness;

    protected function setUp(): void
    {
        parent::setUp();
        $runtime = new class implements WhatsAppRuntimeConfiguration
        {
            public function all(): array
            {
                $config = config('services.whatsapp', []);
                unset($config['access_token']);

                return $config;
            }

            public function accessToken(): ?string
            {
                return $this->providerCredentials()['access_token'];
            }

            public function providerSnapshot(): array
            {
                $credentials = $this->providerCredentials();

                return [
                    'config' => $this->all(),
                    'access_token' => $credentials['access_token'],
                    'channel_integration_id' => $credentials['channel_integration_id'],
                ];
            }

            public function providerCredentials(): array
            {
                $token = config('services.whatsapp.access_token');
                $channelIntegrationId = config('services.whatsapp.channel_integration_id');

                return [
                    'access_token' => is_string($token) && trim($token) !== '' ? $token : null,
                    'channel_integration_id' => is_string($channelIntegrationId) && trim($channelIntegrationId) !== ''
                        ? $channelIntegrationId
                        : null,
                ];
            }
        };

        $this->readiness = new WhatsAppReadiness($runtime);
    }

    public function test_readiness_false_secara_default(): void
    {
        $this->assertFalse($this->readiness->isReady());
    }

    public function test_readiness_true_saat_seluruh_syarat_dan_template_generalisasi_terkonfigurasi(): void
    {
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => 'qontak',
            'services.whatsapp.base_url' => QontakWhatsAppProviderContract::BASE_URL,
            'services.whatsapp.access_token' => 'token_rahasia_123',
            'services.whatsapp.channel_integration_id' => 'chan_456',
            'services.whatsapp.template_configuration' => 'tmpl_conf_789',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.lldikti16.kemdikbud.go.id',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'alasan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_cuti_status' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_ews_pengingat' => $this->validContract(['nama_pegawai', 'jenis_peringatan', 'tanggal_target', 'sisa_waktu', 'tautan_detail']),
        ]);

        $this->assertTrue($this->readiness->isReady());
    }

    public function test_readiness_true_saat_template_dipecah_per_event_dan_semua_kontrak_lengkap(): void
    {
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => 'qontak',
            'services.whatsapp.base_url' => QontakWhatsAppProviderContract::BASE_URL,
            'services.whatsapp.access_token' => 'token_rahasia_123',
            'services.whatsapp.channel_integration_id' => 'chan_456',
            'services.whatsapp.template_configuration' => 'tmpl_conf_789',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.lldikti16.kemdikbud.go.id',
            'services.whatsapp.event_templates' => array_replace(WhatsAppTemplateContract::eventTemplateArchetypes(), [
                'cuti.disetujui' => 'cuti_disetujui_v1',
                'cuti.ditunda' => 'cuti_ditunda_v1',
            ]),
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'alasan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_cuti_status' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_ews_pengingat' => $this->validContract(['nama_pegawai', 'jenis_peringatan', 'tanggal_target', 'sisa_waktu', 'tautan_detail']),
            'services.whatsapp.templates.cuti_disetujui_v1' => [
                'archetype' => 'simpeg_cuti_status',
                ...$this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            ],
            'services.whatsapp.templates.cuti_ditunda_v1' => [
                'archetype' => 'simpeg_cuti_status',
                ...$this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            ],
        ]);

        $this->assertTrue($this->readiness->isReady());
    }

    public function test_readiness_false_bila_mapping_event_runtime_hanya_sebagian(): void
    {
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => 'qontak',
            'services.whatsapp.base_url' => 'https://api.qontak.test',
            'services.whatsapp.access_token' => 'token_rahasia_123',
            'services.whatsapp.channel_integration_id' => 'chan_456',
            'services.whatsapp.template_configuration' => 'tmpl_conf_789',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.lldikti16.kemdikbud.go.id',
            'services.whatsapp.event_templates' => [
                'cuti.disetujui' => 'cuti_disetujui_v1',
                'cuti.ditunda' => 'cuti_ditunda_v1',
            ],
            'services.whatsapp.templates.cuti_disetujui_v1' => [
                'archetype' => 'simpeg_cuti_status',
                ...$this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            ],
            // Mapping event yang tidak lengkap tidak boleh dianggap aman melalui fallback archetype.
        ]);

        $this->assertFalse($this->readiness->isReady());
    }

    public function test_readiness_menerima_kontrak_provider_yang_didekode_dari_konfigurasi_runtime(): void
    {
        $runtimeConfiguration = json_encode([
            'event_templates' => [
                'cuti.disetujui' => 'provider_cuti_disetujui_v1',
            ],
            'templates' => [
                'simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'alasan', 'tautan_detail']),
                'simpeg_cuti_status' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
                'simpeg_ews_pengingat' => $this->validContract(['nama_pegawai', 'jenis_peringatan', 'tanggal_target', 'sisa_waktu', 'tautan_detail']),
                'provider_cuti_disetujui_v1' => [
                    'archetype' => 'simpeg_cuti_status',
                    ...$this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $decoded = WhatsAppTemplateConfiguration::decode($runtimeConfiguration);

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => 'qontak',
            'services.whatsapp.base_url' => QontakWhatsAppProviderContract::BASE_URL,
            'services.whatsapp.access_token' => 'credential-reference',
            'services.whatsapp.channel_integration_id' => 'channel-resmi',
            'services.whatsapp.template_configuration' => $runtimeConfiguration,
            'services.whatsapp.runtime_configuration_valid' => $decoded['valid'],
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.event_templates' => array_replace(
                config('services.whatsapp.event_templates'),
                $decoded['event_templates'],
            ),
            'services.whatsapp.templates' => $decoded['templates'],
        ]);

        $this->assertTrue($this->readiness->isReady());
        $eventTemplates = config('services.whatsapp.event_templates');
        $this->assertSame('provider_cuti_disetujui_v1', $eventTemplates['cuti.disetujui']);
    }

    public function test_readiness_menolak_runtime_configuration_yang_malformed_meski_default_template_tersedia(): void
    {
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => 'qontak',
            'services.whatsapp.base_url' => 'https://provider.example.test',
            'services.whatsapp.access_token' => 'credential-reference',
            'services.whatsapp.channel_integration_id' => 'channel-resmi',
            'services.whatsapp.template_configuration' => '{invalid json',
            'services.whatsapp.runtime_configuration_valid' => false,
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'alasan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_cuti_status' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_ews_pengingat' => $this->validContract(['nama_pegawai', 'jenis_peringatan', 'tanggal_target', 'sisa_waktu', 'tautan_detail']),
        ]);

        $this->assertFalse($this->readiness->isReady());
    }

    public function test_readiness_menolak_base_url_di_luar_kontrak_transport_qontak(): void
    {
        foreach ([
            'https://provider.example.test',
            'http://service-chat.qontak.com/api/open/v1',
            'https://service-chat.qontak.com.evil.example/api/open/v1',
        ] as $baseUrl) {
            $this->configureReadinessValid($baseUrl);

            $this->assertFalse($this->readiness->isReady(), $baseUrl);
        }
    }

    public function test_readiness_menolak_provider_selain_qontak(): void
    {
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            // Binding aplikasi hanya menyediakan adapter Qontak; provider lain ditolak.
            'services.whatsapp.provider' => 'meta_cloud_api',
            'services.whatsapp.base_url' => 'https://provider.example.test',
            'services.whatsapp.access_token' => 'token_rahasia_123',
            'services.whatsapp.channel_integration_id' => 'chan_456',
            'services.whatsapp.template_configuration' => 'tmpl_conf_789',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'alasan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_cuti_status' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_ews_pengingat' => $this->validContract(['nama_pegawai', 'jenis_peringatan', 'tanggal_target', 'sisa_waktu', 'tautan_detail']),
        ]);

        $this->assertFalse($this->readiness->isReady());
    }

    public function test_readiness_menolak_template_split_yang_menaruh_tautan_detail_di_body(): void
    {
        $splitContract = [
            'archetype' => 'simpeg_cuti_status',
            'id' => 'tmpl_split_lama',
            'language' => 'id',
            // Kontrak lama yang masih memuat tautan_detail di body harus ditolak
            // agar delivery tidak diantrekan lalu selalu diskip worker.
            'variables_map' => [
                'nama_pegawai' => '1',
                'status' => '2',
                'tautan_detail' => '3',
            ],
            'button' => ['type' => 'url', 'parameter' => 'button_target_url'],
        ];

        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => 'qontak',
            'services.whatsapp.base_url' => 'https://api.qontak.test',
            'services.whatsapp.access_token' => 'token_rahasia_123',
            'services.whatsapp.channel_integration_id' => 'chan_456',
            'services.whatsapp.template_configuration' => 'tmpl_conf_789',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.event_templates' => array_replace(WhatsAppTemplateContract::eventTemplateArchetypes(), [
                'cuti.disetujui' => 'cuti_disetujui_split_lama',
            ]),
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'alasan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_cuti_status' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_ews_pengingat' => $this->validContract(['nama_pegawai', 'jenis_peringatan', 'tanggal_target', 'sisa_waktu', 'tautan_detail']),
            'services.whatsapp.templates.cuti_disetujui_split_lama' => $splitContract,
        ]);

        $this->assertFalse($this->readiness->isReady());
        $this->assertFalse(WhatsAppTemplateContract::isConfigured('cuti_disetujui_split_lama', $splitContract));
    }

    public function test_kontrak_menolak_spasi_tepi_pada_identitas_dan_parameter_provider(): void
    {
        $contract = [
            'archetype' => 'simpeg_cuti_status',
            'id' => 'tmpl_split_spasi',
            'language' => 'id',
            'variables_map' => ['nama_pegawai' => ' 1'],
            'button' => ['type' => 'url', 'parameter' => 'button_target_url'],
        ];
        $buttonDenganSpasi = [
            ...$contract,
            'variables_map' => ['nama_pegawai' => '1'],
            'button' => ['type' => 'url', 'parameter' => 'button_target_url '],
        ];
        $idDenganSpasi = [
            ...$contract,
            'id' => ' tmpl_split_spasi',
            'variables_map' => ['nama_pegawai' => '1'],
        ];
        $bahasaDenganNbsp = [
            ...$contract,
            'language' => "id\u{00A0}",
            'variables_map' => ['nama_pegawai' => '1'],
        ];
        $contractValid = [
            ...$contract,
            'variables_map' => ['nama_pegawai' => '1'],
        ];

        $this->assertSame([
            'variables_map' => false,
            'button' => false,
            'id' => false,
            'language' => false,
            'queued_id' => false,
        ], [
            'variables_map' => WhatsAppTemplateContract::isConfigured('cuti_status_split_spasi', $contract),
            'button' => WhatsAppTemplateContract::isConfigured('cuti_status_split_spasi', $buttonDenganSpasi),
            'id' => WhatsAppTemplateContract::isConfigured('cuti_status_split_spasi', $idDenganSpasi),
            'language' => WhatsAppTemplateContract::isConfigured('cuti_status_split_spasi', $bahasaDenganNbsp),
            'queued_id' => WhatsAppTemplateContract::matchesQueuedPayload(
                'cuti_status_split_spasi',
                'tmpl_split_spasi ',
                'id',
                ['1' => 'Pegawai'],
                ['button_target_url' => 'https://simpeg.example.test/cuti/1'],
                ['nama_pegawai' => '1'],
                $contractValid,
                'https://simpeg.example.test',
                'simpeg_cuti_status',
            ),
        ]);
    }

    /**
     * @param  list<string>  $variables
     * @return array{id: string, language: string, variables_map: array<string, string>, button: array{type: string, parameter: string}}
     */
    private function validContract(array $variables): array
    {
        // Tautan detail tidak pernah menjadi parameter body; ia disalurkan ke tombol URL.
        $variablesMap = [];
        $position = 0;
        foreach ($variables as $var) {
            if ($var === 'tautan_detail') {
                continue;
            }
            $position++;
            $variablesMap[$var] = (string) $position;
        }

        return [
            'id' => 'tmpl_'.uniqid(),
            'language' => 'id',
            'variables_map' => $variablesMap,
            'button' => [
                'type' => 'url',
                'parameter' => 'button_target_url',
            ],
        ];
    }

    private function configureReadinessValid(string $baseUrl): void
    {
        config([
            'services.whatsapp.enabled' => true,
            'services.whatsapp.sandbox_verified' => true,
            'services.whatsapp.recipient_source_verified' => true,
            'services.whatsapp.provider' => QontakWhatsAppProviderContract::PROVIDER,
            'services.whatsapp.base_url' => $baseUrl,
            'services.whatsapp.access_token' => 'credential-reference',
            'services.whatsapp.channel_integration_id' => 'channel-resmi',
            'services.whatsapp.template_configuration' => 'kontrak-template-resmi',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'alasan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_cuti_status' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_ews_pengingat' => $this->validContract(['nama_pegawai', 'jenis_peringatan', 'tanggal_target', 'sisa_waktu', 'tautan_detail']),
        ]);
    }
}
