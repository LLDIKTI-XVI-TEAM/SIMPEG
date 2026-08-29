<?php

namespace App\Services\Notifications\WhatsApp;

final class WhatsAppDeliveryResult
{
    /** @var list<string> */
    public const ALLOWED_FAILURE_CODES = [
        'provider_unavailable',
        'provider_misconfigured',
        'network_timeout',
        'rate_limited',
        'recipient_invalid',
        'template_mismatch',
        'delivery_rejected',
        'delivery_failed',
        'unknown_error',
    ];

    private function __construct(
        public readonly bool $delivered,
        public readonly string $code,
    ) {}

    public static function delivered(): self
    {
        return new self(true, 'delivered');
    }

    public static function unavailable(): self
    {
        return new self(false, 'provider_unavailable');
    }

    public static function failed(string $code = 'delivery_failed'): self
    {
        $safeCode = in_array($code, self::ALLOWED_FAILURE_CODES, true)
            ? $code
            : 'delivery_failed';

        return new self(false, $safeCode);
    }
}
