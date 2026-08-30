<?php

namespace App\Services\Notifications\WhatsApp;

use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adapter pengiriman WhatsApp Business via API broadcast direct Qontak.
 *
 * Endpoint: POST {base_url}/broadcasts/whatsapp/direct dengan Authorization Bearer.
 * Format parameter mengikuti kontrak Qontak yang terbukti di aplikasi LLDIKTI lain:
 * body params memakai key posisi (integer), value nama variabel/kunci kontrak,
 * dan value_text isi pesan; tombol URL memakai index/type/value dengan value
 * berupa bagian path yang menggantikan {{1}} pada URL tombol template.
 * Artefak runtime dibaca melalui kontrak setting aplikasi. Token hanya tersedia
 * sebagai hasil dekripsi runtime; Channel Integration ID tetap wajib tersedia.
 * Konfigurasi yang tidak lengkap atau tidak allowlisted menghasilkan kegagalan
 * provider_misconfigured tanpa pemanggilan HTTP.
 */
final class QontakWhatsAppTemplateAdapter implements WhatsAppTemplateAdapter
{
    private const HTTP_TIMEOUT_SECONDS = 15;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    /** @var list<int> cURL: DNS, koneksi TCP, serta handshake/verifikasi TLS gagal sebelum request. */
    private const PRE_REQUEST_CURL_ERRORS = [6, 7, 35, 60];

    private const CURL_OPERATION_TIMED_OUT = 28;

    public function __construct(
        private readonly WhatsAppRuntimeConfiguration $runtime,
        private readonly WhatsAppPrivacyGuard $privacy,
    ) {}

    public function send(WhatsAppTemplateMessage $message): WhatsAppDeliveryResult
    {
        $snapshot = $this->runtime->providerSnapshot();
        $config = $snapshot['config'];
        $baseUrl = is_string($config['base_url'] ?? null) ? trim($config['base_url']) : '';
        $token = $snapshot['access_token'];
        $channelIntegrationId = is_string($snapshot['channel_integration_id'])
            ? trim($snapshot['channel_integration_id'])
            : '';
        $canonicalUrl = is_string($config['canonical_url'] ?? null) ? trim($config['canonical_url']) : '';

        if (! QontakWhatsAppProviderContract::supports($config['provider'] ?? null, $baseUrl)
            || $token === null || $channelIntegrationId === '' || $canonicalUrl === '') {
            return WhatsAppDeliveryResult::failed('provider_misconfigured');
        }

        if (! $this->messageMatchesSnapshotContract($message, $config, $canonicalUrl)) {
            // Rotasi konfigurasi setelah validasi job tidak boleh mengirim payload lama
            // menggunakan credential atau channel dari snapshot provider yang baru.
            return WhatsAppDeliveryResult::failed('delivery_rejected');
        }

        $buttonParams = $this->buildButtonParams($message->buttonVariables, $canonicalUrl);
        if ($buttonParams === null) {
            // Payload yang tidak memenuhi kontrak template tidak boleh diteruskan
            // ke provider walaupun konfigurasi runtime telah valid.
            return WhatsAppDeliveryResult::failed('delivery_rejected');
        }

        $recipientName = $this->privacy->sanitize($message->recipientName);
        if (! $this->privacy->isSafe($recipientName)) {
            // Nama hanya metadata provider; fallback menjaga delivery tanpa membocorkan data sensitif.
            $recipientName = 'Penerima SIMPEG';
        }

        $payload = [
            'to_name' => $recipientName ?: 'Penerima SIMPEG',
            'to_number' => $message->recipientAddress,
            'message_template_id' => $message->templateId,
            'channel_integration_id' => $channelIntegrationId,
            'language' => ['code' => $message->language],
            'parameters' => [
                'body' => $this->buildBodyParams($message->bodyVariables),
                'buttons' => $buttonParams,
            ],
        ];

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(self::HTTP_TIMEOUT_SECONDS)
                ->connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                ->post(rtrim($baseUrl, '/').'/broadcasts/whatsapp/direct', $payload);
        } catch (ConnectionException $exception) {
            $failureCode = $this->failedBeforeRequestWasSent($exception)
                ? 'provider_unavailable'
                : 'network_timeout';

            return WhatsAppDeliveryResult::failed($failureCode);
        } catch (Throwable $exception) {
            Log::warning('Pemanggilan provider WhatsApp gagal sebelum respons diterima.', [
                'exception' => $exception::class,
            ]);

            return WhatsAppDeliveryResult::failed('provider_unavailable');
        }

        if ($response->successful()) {
            return WhatsAppDeliveryResult::delivered();
        }

        return WhatsAppDeliveryResult::failed($this->mapFailureCode($response->status()));
    }

    /**
     * Memvalidasi ulang seluruh payload terhadap snapshot yang juga dipakai untuk POST.
     *
     * @param  array<string, mixed>  $config
     */
    private function messageMatchesSnapshotContract(
        WhatsAppTemplateMessage $message,
        array $config,
        string $canonicalUrl,
    ): bool {
        $eventTemplates = $config['event_templates'] ?? null;
        $templates = $config['templates'] ?? null;
        $archetype = WhatsAppTemplateContract::eventTemplateArchetypes()[$message->eventKey] ?? null;
        if (! is_array($eventTemplates) || ! is_array($templates) || ! is_string($archetype)) {
            return false;
        }

        $currentTemplateKey = $eventTemplates[$message->eventKey] ?? null;
        if (! is_string($currentTemplateKey)
            || ! hash_equals($currentTemplateKey, $message->templateKey)) {
            return false;
        }

        $templateConfig = $templates[$currentTemplateKey] ?? null;
        if (! is_array($templateConfig)) {
            return false;
        }

        return WhatsAppTemplateContract::matchesQueuedPayload(
            $currentTemplateKey,
            $message->templateId,
            $message->language,
            $message->bodyVariables,
            $message->buttonVariables,
            $message->variablesMap,
            $templateConfig,
            $canonicalUrl,
            $archetype,
        );
    }

    /**
     * Hanya koneksi yang terbukti gagal sebelum transfer POST aman dicoba ulang.
     * Konteks yang hilang atau transfer yang sudah dimulai tetap dianggap ambigu.
     */
    private function failedBeforeRequestWasSent(ConnectionException $exception): bool
    {
        $previous = $exception->getPrevious();
        if ($previous instanceof GuzzleConnectException) {
            $context = $previous->getHandlerContext();
        } elseif ($previous instanceof GuzzleRequestException && ! $previous->hasResponse()) {
            // Guzzle membungkus sebagian error TLS pra-response sebagai RequestException.
            $context = $previous->getHandlerContext();
        } else {
            return false;
        }

        $errno = $context['errno'] ?? null;
        if (! is_int($errno)) {
            return false;
        }

        if (in_array($errno, self::PRE_REQUEST_CURL_ERRORS, true)) {
            return true;
        }

        if ($errno !== self::CURL_OPERATION_TIMED_OUT
            || ! is_numeric($context['pretransfer_time'] ?? null)
            || ! is_numeric($context['request_size'] ?? null)
            || ! is_numeric($context['size_upload'] ?? null)) {
            return false;
        }

        return (float) $context['pretransfer_time'] <= 0.0
            && (int) $context['request_size'] === 0
            && (float) $context['size_upload'] <= 0.0;
    }

    /**
     * Kunci kontrak bisa berupa posisi angka ("1".."6") atau nama variabel provider.
     * Qontak membaca key sebagai posisi body dan value sebagai label variabel; urutan
     * iterasi mengikuti urutan variables_map kontrak provider.
     *
     * @param  array<int|string, string>  $bodyVariables
     * @return list<array{key: int, value: string, value_text: string}>
     */
    private function buildBodyParams(array $bodyVariables): array
    {
        $params = [];
        $position = 0;

        foreach ($bodyVariables as $providerKey => $content) {
            $position++;
            $params[] = [
                'key' => $position,
                'value' => (string) $providerKey,
                'value_text' => (string) $content,
            ];
        }

        return $params;
    }

    /**
     * Satu tombol URL per template. Value menggantikan {{1}} pada URL tombol template,
     * sehingga hanya berisi path setelah canonical URL (diawali "/").
     *
     * @param  array<int|string, string>  $buttonVariables
     * @return list<array{index: string, type: string, value: string}>|null
     */
    private function buildButtonParams(array $buttonVariables, string $canonicalUrl): ?array
    {
        $params = [];
        $prefix = rtrim($canonicalUrl, '/');
        $canonical = parse_url($prefix);

        if (! is_array($canonical)
            || strtolower((string) ($canonical['scheme'] ?? '')) !== 'https'
            || ! is_string($canonical['host'] ?? null)
            || $canonical['host'] === '') {
            return null;
        }

        foreach ($buttonVariables as $buttonUrl) {
            $buttonUrl = (string) $buttonUrl;
            $parsed = parse_url($buttonUrl);

            if (! is_array($parsed)
                || strtolower((string) ($parsed['scheme'] ?? '')) !== 'https'
                || strtolower((string) ($parsed['host'] ?? '')) !== strtolower($canonical['host'])
                || ! str_starts_with($buttonUrl, $prefix.'/')) {
                return null;
            }

            $buttonValue = substr($buttonUrl, strlen($prefix));
            if (! is_string($buttonValue) || ! str_starts_with($buttonValue, '/')) {
                return null;
            }

            $params[] = [
                'index' => '0',
                'type' => 'url',
                'value' => $buttonValue,
            ];
        }

        return $params;
    }

    /**
     * Memetakan status HTTP provider ke kode kegagalan yang diizinkan delivery audit.
     */
    private function mapFailureCode(int $status): string
    {
        if ($status === 429) {
            return 'rate_limited';
        }

        if ($status === 401 || $status === 403) {
            // Token tidak valid atau kedaluwarsa; konfigurasi perlu diperbarui operator.
            return 'provider_misconfigured';
        }

        if ($status >= 500) {
            // Respons 5xx setelah POST tidak memastikan apakah provider sudah menerima pesan.
            return 'provider_response_ambiguous';
        }

        return 'delivery_rejected';
    }
}
