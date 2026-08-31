<?php

namespace App\Services\Notifications\WhatsApp;

/**
 * Payload in-memory untuk adapter; alamat tujuan tidak boleh dipersistenkan ke delivery audit.
 *
 * @param  array<int|string, string>  $bodyVariables
 * @param  array<int|string, string>  $buttonVariables
 * @param  array<string, string>|null  $variablesMap
 */
final readonly class WhatsAppTemplateMessage
{
    public function __construct(
        public string $idempotencyKey,
        public string $eventKey,
        public string $templateKey,
        public string $templateId,
        public string $language,
        public string $recipientAddress,
        public array $bodyVariables = [],
        public array $buttonVariables = [],
        public string $recipientName = '',
        public ?array $variablesMap = null,
    ) {}
}
