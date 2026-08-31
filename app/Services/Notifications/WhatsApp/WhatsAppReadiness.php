<?php

namespace App\Services\Notifications\WhatsApp;

class WhatsAppReadiness
{
    public function __construct(
        private readonly WhatsAppRuntimeConfiguration $runtime,
    ) {}

    /**
     * WhatsApp tidak boleh aktif hanya karena satu environment variable terisi.
     * Gerbang operasional (kill-switch) dibaca dari environment, sedangkan artefak
     * provider dibaca dari setting aplikasi via WhatsAppRuntimeConfig. Token berasal
     * dari ciphertext write-only database, sedangkan ID integrasi berasal dari UUID
     * resmi di setting. Seluruh artefak harus terisi dan tervalidasi sebelum dispatcher bekerja.
     */
    public function isReady(): bool
    {
        $snapshot = $this->runtime->providerSnapshot();
        $config = $snapshot['config'];

        if (($config['enabled'] ?? false) !== true
            || ($config['sandbox_verified'] ?? false) !== true
            || ($config['recipient_source_verified'] ?? false) !== true
            || ! QontakWhatsAppProviderContract::supports(
                $config['provider'] ?? null,
                $config['base_url'] ?? null,
            )
            || blank($snapshot['access_token'])
            || blank($snapshot['channel_integration_id'])
            || blank($config['template_configuration'] ?? null)
            || ($config['runtime_configuration_valid'] ?? false) !== true
            || blank($config['canonical_url'] ?? null)) {
            return false;
        }

        $eventTemplates = $config['event_templates'] ?? null;
        $requiredEventTemplates = WhatsAppTemplateContract::eventTemplateArchetypes();
        if (! is_array($eventTemplates)
            || array_diff_key($eventTemplates, $requiredEventTemplates) !== []
            || array_diff_key($requiredEventTemplates, $eventTemplates) !== []) {
            return false;
        }

        foreach ($requiredEventTemplates as $eventKey => $archetype) {
            $templateKey = $eventTemplates[$eventKey] ?? null;
            if (! is_string($templateKey) || trim($templateKey) === '') {
                return false;
            }

            $templateConfig = $config['templates'][$templateKey] ?? null;
            if (! WhatsAppTemplateContract::isConfigured($templateKey, $templateConfig, $archetype)) {
                return false;
            }
        }

        return true;
    }
}
