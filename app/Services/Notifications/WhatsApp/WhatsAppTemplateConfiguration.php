<?php

namespace App\Services\Notifications\WhatsApp;

use JsonException;

/**
 * Membaca kontrak template resmi provider dari satu konfigurasi JSON.
 *
 * Konfigurasi tidak memiliki fallback ke nama variabel proposal. JSON tidak valid atau
 * tidak lengkap menghasilkan struktur kosong yang kemudian ditolak oleh readiness guard.
 */
final class WhatsAppTemplateConfiguration
{
    /**
     * @param  list<string>  $allowedEventKeys
     * @return array{valid: bool, event_templates: array<string, string>, templates: array<string, array<string, mixed>>}
     */
    public static function decode(?string $configuration, array $allowedEventKeys = []): array
    {
        if (! is_string($configuration) || trim($configuration) === '') {
            // Kontrak runtime wajib diberikan saat provider diaktifkan; konfigurasi kosong bukan kontrak valid.
            return self::empty(false);
        }

        try {
            $decoded = json_decode($configuration, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::empty(false);
        }

        if (! is_array($decoded)) {
            return self::empty(false);
        }

        $eventTemplates = [];
        $configuredEventTemplates = $decoded['event_templates'] ?? null;
        if (! is_array($configuredEventTemplates)) {
            return self::empty(false);
        }

        foreach ($configuredEventTemplates as $eventKey => $templateKey) {
            if (! is_string($eventKey) || $eventKey === ''
                || ! is_string($templateKey) || $templateKey === ''
                || ($allowedEventKeys !== [] && ! in_array($eventKey, $allowedEventKeys, true))) {
                return self::empty(false);
            }

            $eventTemplates[$eventKey] = $templateKey;
        }

        $templates = [];
        $configuredTemplates = $decoded['templates'] ?? null;
        if (! is_array($configuredTemplates)) {
            return self::empty(false);
        }

        foreach ($configuredTemplates as $templateKey => $template) {
            if (! is_string($templateKey) || $templateKey === '' || ! is_array($template)) {
                return self::empty(false);
            }

            $templates[$templateKey] = $template;
        }

        return [
            'valid' => true,
            'event_templates' => $eventTemplates,
            'templates' => $templates,
        ];
    }

    /**
     * @return array{valid: bool, event_templates: array<string, string>, templates: array<string, array<string, mixed>>}
     */
    private static function empty(bool $valid): array
    {
        return ['valid' => $valid, 'event_templates' => [], 'templates' => []];
    }
}
