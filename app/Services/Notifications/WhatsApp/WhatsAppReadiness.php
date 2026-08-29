<?php

namespace App\Services\Notifications\WhatsApp;

class WhatsAppReadiness
{
    /**
     * WhatsApp tidak boleh aktif hanya karena satu environment variable terisi.
     * Seluruh artefak provider harus sudah diverifikasi oleh LLDIKTI sebelum dispatcher dapat bekerja.
     */
    public function isReady(): bool
    {
        $config = config('services.whatsapp', []);

        if (($config['enabled'] ?? false) !== true
            || ($config['sandbox_verified'] ?? false) !== true
            || ($config['recipient_source_verified'] ?? false) !== true
            || blank($config['provider'] ?? null)
            || blank($config['base_url'] ?? null)
            || blank($config['credential_reference'] ?? null)
            || blank($config['channel_id'] ?? null)
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
