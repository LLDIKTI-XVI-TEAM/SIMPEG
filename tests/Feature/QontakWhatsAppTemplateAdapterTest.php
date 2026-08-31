<?php

namespace Tests\Feature;

use App\Services\Notifications\WhatsApp\QontakWhatsAppTemplateAdapter;
use App\Services\Notifications\WhatsApp\WhatsAppDeliveryResult;
use App\Services\Notifications\WhatsApp\WhatsAppPrivacyGuard;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfiguration;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateMessage;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QontakWhatsAppTemplateAdapterTest extends TestCase
{
    private QontakWhatsAppTemplateAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.provider' => 'qontak',
            'services.whatsapp.base_url' => 'https://service-chat.qontak.com/api/open/v1',
            'services.whatsapp.access_token' => 'fixture-token-qontak-adapter',
            'services.whatsapp.channel_integration_id' => 'fixture-channel-integration-adapter',
            'services.whatsapp.canonical_url' => 'https://simpeg.lldiktiwil16.id',
            'services.whatsapp.event_templates' => [
                'cuti.pengajuan_baru' => 'simpeg16_cuti_perlu_tindakan',
            ],
            'services.whatsapp.templates.simpeg16_cuti_perlu_tindakan' => [
                'id' => 'bf2a5c38-8cf6-4d12-bec4-d852ff4ea51f',
                'language' => 'id',
                'variables_map' => $this->positionalVariablesMap(),
                'button' => [
                    'type' => 'url',
                    'parameter' => 'button_target_url',
                ],
            ],
        ]);

        $this->adapter = new QontakWhatsAppTemplateAdapter($this->fakeRuntime(), new WhatsAppPrivacyGuard);
    }

    private function message(array $overrides = []): WhatsAppTemplateMessage
    {
        return new WhatsAppTemplateMessage(
            idempotencyKey: 'idem-1',
            eventKey: 'cuti.pengajuan_baru',
            templateKey: 'simpeg16_cuti_perlu_tindakan',
            templateId: 'bf2a5c38-8cf6-4d12-bec4-d852ff4ea51f',
            language: 'id',
            recipientAddress: '6281234567890',
            bodyVariables: $overrides['bodyVariables'] ?? [
                '1' => 'Ahmad Fauzi',
                '2' => 'Cuti Tahunan',
                '3' => '01 September 2026',
                '4' => '03 September 2026',
                '5' => '3 hari kerja',
                '6' => 'Keperluan keluarga',
            ],
            buttonVariables: $overrides['buttonVariables'] ?? [
                'button_target_url' => 'https://simpeg.lldiktiwil16.id/dashboard/cuti/9b1deb4d',
            ],
            recipientName: $overrides['recipientName'] ?? 'Ahmad <b>Fauzi</b>',
            variablesMap: $overrides['variablesMap'] ?? $this->positionalVariablesMap(),
        );
    }

    /** @return array<string, string> */
    private function positionalVariablesMap(): array
    {
        return [
            'nama_pegawai' => '1',
            'jenis_cuti' => '2',
            'tanggal_mulai' => '3',
            'tanggal_selesai' => '4',
            'jumlah_hari' => '5',
            'alasan' => '6',
        ];
    }

    public function test_mengirim_payload_kontrak_qontak_dan_mengembalikan_delivered(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = $this->adapter->send($this->message());

        $this->assertTrue($result->delivered);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url() === 'https://service-chat.qontak.com/api/open/v1/broadcasts/whatsapp/direct'
                && $request->hasHeader('Authorization', 'Bearer fixture-token-qontak-adapter')
                && $data['to_number'] === '6281234567890'
                && $data['to_name'] === 'Ahmad Fauzi'
                && $data['message_template_id'] === 'bf2a5c38-8cf6-4d12-bec4-d852ff4ea51f'
                && $data['channel_integration_id'] === 'fixture-channel-integration-adapter'
                && $data['language']['code'] === 'id'
                && $data['parameters']['body'][0] === ['key' => 1, 'value' => '1', 'value_text' => 'Ahmad Fauzi']
                && $data['parameters']['body'][5]['value_text'] === 'Keperluan keluarga'
                && $data['parameters']['buttons'][0] === [
                    'index' => '0',
                    'type' => 'url',
                    'value' => '/dashboard/cuti/9b1deb4d',
                ];
        });
    }

    public function test_kunci_parameter_named_dikirim_sebagai_label_variabel(): void
    {
        $namedVariablesMap = [
            'nama_pegawai' => 'nama_pegawai',
            'jenis_cuti' => 'jenis_cuti',
        ];
        config([
            'services.whatsapp.templates.simpeg16_cuti_perlu_tindakan.variables_map' => $namedVariablesMap,
        ]);
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $this->adapter->send($this->message([
            'bodyVariables' => [
                'nama_pegawai' => 'Budi Santoso',
                'jenis_cuti' => 'Cuti Tahunan',
            ],
            'variablesMap' => $namedVariablesMap,
        ]));

        Http::assertSent(function ($request): bool {
            $body = $request->data()['parameters']['body'];

            return $body[0]['key'] === 1
                && $body[0]['value'] === 'nama_pegawai'
                && $body[0]['value_text'] === 'Budi Santoso'
                && $body[1]['key'] === 2
                && $body[1]['value'] === 'jenis_cuti'
                && $body[1]['value_text'] === 'Cuti Tahunan';
        });
    }

    public function test_tombol_di_luar_domain_canonical_ditolak_sebelum_request_provider(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = $this->adapter->send($this->message([
            'buttonVariables' => ['button_target_url' => 'https://domain-lain.example/cuti/1'],
        ]));

        $this->assertFalse($result->delivered);
        $this->assertSame('delivery_rejected', $result->code);
        Http::assertNothingSent();
    }

    public function test_nama_penerima_sensitif_diganti_fallback_sebelum_request_provider(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);

        $result = $this->adapter->send($this->message([
            'recipientName' => 'Pegawai 1234567890123456',
        ]));

        $this->assertTrue($result->delivered);
        Http::assertSent(fn ($request): bool => $request->data()['to_name'] === 'Penerima SIMPEG');
    }

    public function test_konfigurasi_tidak_lengkap_gagal_tanpa_pemanggilan_http(): void
    {
        config(['services.whatsapp.channel_integration_id' => null]);
        Http::fake();

        $result = $this->adapter->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertSame('provider_misconfigured', $result->code);
        Http::assertNothingSent();
    }

    public function test_base_url_di_luar_allowlist_qontak_gagal_tanpa_mengirim_token(): void
    {
        foreach ([
            'http://service-chat.qontak.com/api/open/v1',
            'https://evil.example.com/api/open/v1',
            'https://service-chat.qontak.com.evil.example/api/open/v1',
            'https://127.0.0.1/api/open/v1',
        ] as $baseUrl) {
            config(['services.whatsapp.base_url' => $baseUrl]);
            $adapter = new QontakWhatsAppTemplateAdapter($this->fakeRuntime(), new WhatsAppPrivacyGuard);
            Http::fake();

            $result = $adapter->send($this->message());

            $this->assertFalse($result->delivered, $baseUrl);
            $this->assertSame('provider_misconfigured', $result->code, $baseUrl);
            Http::assertNothingSent();
        }
    }

    public function test_status_http_dipetakan_ke_kode_kegagalan_yang_dizinkan(): void
    {
        $cases = [
            400 => 'delivery_rejected',
            401 => 'provider_misconfigured',
            403 => 'provider_misconfigured',
            429 => 'rate_limited',
            500 => 'provider_response_ambiguous',
            502 => 'provider_response_ambiguous',
            503 => 'provider_response_ambiguous',
            504 => 'provider_response_ambiguous',
        ];

        // Satu sequence berisi seluruh respons agar tiap pemanggilan adapter memakai
        // status berbeda tanpa mendaftarkan ulang fake.
        $sequence = Http::fakeSequence('*');
        foreach (array_keys($cases) as $status) {
            $sequence->push(['status' => 'error'], $status);
        }

        foreach ($cases as $status => $expectedCode) {
            $result = $this->adapter->send($this->message());

            $this->assertFalse($result->delivered, "Status {$status} seharusnya gagal.");
            $this->assertSame($expectedCode, $result->code, "Status {$status} salah kode.");
        }
    }

    public function test_koneksi_gagal_menghasilkan_network_timeout(): void
    {
        Http::fake(function (): void {
            throw new ConnectionException('Connection timed out');
        });

        $result = $this->adapter->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertSame('network_timeout', $result->code);
    }

    public function test_kegagalan_koneksi_yang_terbukti_sebelum_post_dapat_dicoba_ulang(): void
    {
        Http::fake(function (): void {
            $request = new Request('POST', 'https://service-chat.qontak.com/api/open/v1/broadcasts/whatsapp/direct');
            $guzzleException = new GuzzleConnectException(
                'Connection timed out before transfer',
                $request,
                null,
                [
                    'errno' => 28,
                    'pretransfer_time' => 0.0,
                    'request_size' => 0,
                    'size_upload' => 0.0,
                ],
            );

            throw new ConnectionException('Connection timed out before transfer', 0, $guzzleException);
        });

        $result = $this->adapter->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertSame('provider_unavailable', $result->code);
    }

    public function test_gagal_verifikasi_sertifikat_sebelum_post_dapat_dicoba_ulang(): void
    {
        Http::fake(function (): void {
            $request = new Request('POST', 'https://service-chat.qontak.com/api/open/v1/broadcasts/whatsapp/direct');
            $guzzleException = new GuzzleRequestException(
                'SSL certificate problem',
                $request,
                null,
                null,
                ['errno' => 60],
            );

            throw new ConnectionException('SSL certificate problem', 0, $guzzleException);
        });

        $result = $this->adapter->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertSame('provider_unavailable', $result->code);
    }

    public function test_galat_tak_terduga_menghasilkan_provider_unavailable(): void
    {
        Http::fake(function (): void {
            throw new \RuntimeException('DNS resolution failure');
        });

        $result = $this->adapter->send($this->message());

        $this->assertFalse($result->delivered);
        $this->assertSame('provider_unavailable', $result->code);
        $this->assertContains($result->code, WhatsAppDeliveryResult::ALLOWED_FAILURE_CODES);
    }

    private function fakeRuntime(): WhatsAppRuntimeConfiguration
    {
        return new class implements WhatsAppRuntimeConfiguration
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
    }
}
