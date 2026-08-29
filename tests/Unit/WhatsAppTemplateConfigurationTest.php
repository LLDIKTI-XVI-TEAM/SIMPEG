<?php

namespace Tests\Unit;

use App\Services\Notifications\WhatsApp\WhatsAppTemplateConfiguration;
use PHPUnit\Framework\TestCase;

class WhatsAppTemplateConfigurationTest extends TestCase
{
    public function test_membaca_kontrak_provider_termasuk_template_split_per_event(): void
    {
        $configuration = WhatsAppTemplateConfiguration::decode(json_encode([
            'event_templates' => [
                'cuti.disetujui' => 'provider_cuti_disetujui_v1',
            ],
            'templates' => [
                'provider_cuti_disetujui_v1' => [
                    'archetype' => 'simpeg_cuti_status',
                    'id' => 'provider-template-id',
                    'language' => 'id_ID',
                    'variables_map' => [
                        'nama_pegawai' => '1',
                        'jenis_cuti' => '2',
                        'status' => '3',
                        'keterangan' => '4',
                        'tautan_detail' => '5',
                    ],
                    'button' => ['type' => 'url', 'parameter' => 'cta_url'],
                ],
            ],
            'ignored' => 'tidak dipakai',
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($configuration['valid']);
        $this->assertSame('provider_cuti_disetujui_v1', $configuration['event_templates']['cuti.disetujui']);
        $this->assertSame('provider-template-id', $configuration['templates']['provider_cuti_disetujui_v1']['id']);
        $this->assertSame('cta_url', $configuration['templates']['provider_cuti_disetujui_v1']['button']['parameter']);
    }

    public function test_konfigurasi_json_tidak_valid_ditolak_dengan_struktur_kosong(): void
    {
        $this->assertSame([
            'valid' => false,
            'event_templates' => [],
            'templates' => [],
        ], WhatsAppTemplateConfiguration::decode('{bukan json'));
    }

    public function test_konfigurasi_runtime_kosong_bukan_kontrak_valid(): void
    {
        $configuration = WhatsAppTemplateConfiguration::decode(null);

        $this->assertFalse($configuration['valid']);
        $this->assertSame([], $configuration['event_templates']);
        $this->assertSame([], $configuration['templates']);
    }

    public function test_override_event_bersarang_yang_malformed_ditolak_tanpa_fallback(): void
    {
        $configuration = WhatsAppTemplateConfiguration::decode(json_encode([
            'event_templates' => ['cuti.disetujui' => null],
            'templates' => [],
        ], JSON_THROW_ON_ERROR), ['cuti.disetujui']);

        $this->assertFalse($configuration['valid']);
        $this->assertSame([], $configuration['event_templates']);
    }
}
