<?php

namespace App\Services\Notifications\WhatsApp;

/**
 * Allowlist kontrak transport Qontak yang mencegah credential dikirim ke host lain.
 */
final class QontakWhatsAppProviderContract
{
    public const PROVIDER = 'qontak';

    public const BASE_URL = 'https://service-chat.qontak.com/api/open/v1';

    /**
     * Memastikan konfigurasi menunjuk tepat ke API Qontak yang disetujui.
     * Query, fragment, port, kredensial URL, dan host yang hanya menyerupai Qontak
     * ditolak agar token tidak dapat tereksfiltrasi melalui konfigurasi runtime.
     */
    public static function supports(mixed $provider, mixed $baseUrl): bool
    {
        if (! is_string($provider) || trim($provider) !== self::PROVIDER
            || ! is_string($baseUrl) || trim($baseUrl) === '') {
            return false;
        }

        $parts = parse_url(trim($baseUrl));

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'service-chat.qontak.com'
            && ($parts['path'] ?? '') === '/api/open/v1'
            && ! isset($parts['port'], $parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
    }
}
