<?php

namespace Tests\Unit;

use App\Services\Notifications\WhatsApp\WhatsAppReadiness;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateConfiguration;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;
use Tests\TestCase;

class WhatsAppReadinessTest extends TestCase
{
    private WhatsAppReadiness $readiness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readiness = new WhatsAppReadiness;
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
            'services.whatsapp.base_url' => 'https://api.qontak.test',
            'services.whatsapp.credential_reference' => 'ref_secret_123',
            'services.whatsapp.channel_id' => 'chan_456',
            'services.whatsapp.template_configuration' => 'tmpl_conf_789',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.lldikti16.kemdikbud.go.id',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'tautan_detail']),
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
            'services.whatsapp.base_url' => 'https://api.qontak.test',
            'services.whatsapp.credential_reference' => 'ref_secret_123',
            'services.whatsapp.channel_id' => 'chan_456',
            'services.whatsapp.template_configuration' => 'tmpl_conf_789',
            'services.whatsapp.runtime_configuration_valid' => true,
            'services.whatsapp.canonical_url' => 'https://simpeg.lldikti16.kemdikbud.go.id',
            'services.whatsapp.event_templates' => array_replace(WhatsAppTemplateContract::eventTemplateArchetypes(), [
                'cuti.disetujui' => 'cuti_disetujui_v1',
                'cuti.ditunda' => 'cuti_ditunda_v1',
            ]),
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'tautan_detail']),
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
            'services.whatsapp.credential_reference' => 'ref_secret_123',
            'services.whatsapp.channel_id' => 'chan_456',
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
                'simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'tautan_detail']),
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
            'services.whatsapp.provider' => 'provider_resmi',
            'services.whatsapp.base_url' => 'https://provider.example.test',
            'services.whatsapp.credential_reference' => 'credential-reference',
            'services.whatsapp.channel_id' => 'channel-resmi',
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
            'services.whatsapp.provider' => 'provider_resmi',
            'services.whatsapp.base_url' => 'https://provider.example.test',
            'services.whatsapp.credential_reference' => 'credential-reference',
            'services.whatsapp.channel_id' => 'channel-resmi',
            'services.whatsapp.template_configuration' => '{invalid json',
            'services.whatsapp.runtime_configuration_valid' => false,
            'services.whatsapp.canonical_url' => 'https://simpeg.example.test',
            'services.whatsapp.templates.simpeg_cuti_perlu_tindakan' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'tanggal_mulai', 'tanggal_selesai', 'jumlah_hari', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_cuti_status' => $this->validContract(['nama_pegawai', 'jenis_cuti', 'status', 'keterangan', 'tautan_detail']),
            'services.whatsapp.templates.simpeg_ews_pengingat' => $this->validContract(['nama_pegawai', 'jenis_peringatan', 'tanggal_target', 'sisa_waktu', 'tautan_detail']),
        ]);

        $this->assertFalse($this->readiness->isReady());
    }

    /**
     * @param  list<string>  $variables
     * @return array{id: string, language: string, variables_map: array<string, string>, button: array{type: string, parameter: string}}
     */
    private function validContract(array $variables): array
    {
        $variablesMap = [];
        foreach ($variables as $index => $var) {
            $variablesMap[$var] = (string) ($index + 1);
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
}
