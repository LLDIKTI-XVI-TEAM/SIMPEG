<?php

namespace App\Services\Notifications\WhatsApp;

final class WhatsAppMappedTemplate
{
    /**
     * @param  array<string, string>  $variables
     * @param  array<int|string, string>  $bodyVariables
     * @param  array<int|string, string>  $buttonVariables
     */
    public function __construct(
        public readonly string $eventKey,
        public readonly string $templateKey,
        public readonly string $templateId,
        public readonly string $language,
        public readonly array $variables,
        public readonly array $bodyVariables,
        public readonly array $buttonVariables = [],
    ) {}
}
