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
    /** @var list<string> */
    private const ROOT_FIELDS = ['event_templates', 'templates'];

    /** @var list<string> */
    private const TEMPLATE_FIELDS = [
        'archetype',
        'required_variables',
        'id',
        'language',
        'variables_map',
        'button',
    ];

    /** @var list<string> */
    private const BUTTON_FIELDS = ['type', 'parameter'];

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

        // Kontrak disimpan mentah dan ikut diaudit. Tolak field di luar schema agar
        // alias credential baru tidak dapat melewati blacklist berbasis nama key.
        if (array_diff(array_keys($decoded), self::ROOT_FIELDS) !== []) {
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

        $allowedVariables = self::allowedVariables();

        foreach ($configuredTemplates as $templateKey => $template) {
            if (! is_string($templateKey) || $templateKey === '' || ! is_array($template)) {
                return self::empty(false);
            }

            if (array_diff(array_keys($template), self::TEMPLATE_FIELDS) !== []) {
                return self::empty(false);
            }

            foreach (['archetype', 'id', 'language'] as $stringField) {
                if (array_key_exists($stringField, $template)
                    && $template[$stringField] !== null
                    && ! is_string($template[$stringField])) {
                    return self::empty(false);
                }
            }

            $requiredVariables = $template['required_variables'] ?? null;
            if ($requiredVariables !== null) {
                if (! is_array($requiredVariables) || ! array_is_list($requiredVariables)) {
                    return self::empty(false);
                }

                foreach ($requiredVariables as $variable) {
                    if (! is_string($variable) || ! in_array($variable, $allowedVariables, true)) {
                        return self::empty(false);
                    }
                }
            }

            $variablesMap = $template['variables_map'] ?? null;
            if ($variablesMap !== null) {
                if (! is_array($variablesMap)) {
                    return self::empty(false);
                }

                foreach ($variablesMap as $canonicalVariable => $providerVariable) {
                    if (! is_string($canonicalVariable)
                        || ! in_array($canonicalVariable, $allowedVariables, true)
                        || ! is_string($providerVariable)) {
                        return self::empty(false);
                    }
                }
            }

            $button = $template['button'] ?? null;
            if ($button !== null) {
                if (! is_array($button) || array_diff(array_keys($button), self::BUTTON_FIELDS) !== []) {
                    return self::empty(false);
                }

                foreach ($button as $value) {
                    if ($value !== null && ! is_string($value)) {
                        return self::empty(false);
                    }
                }
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
     * Variabel kontrak dibatasi ke istilah domain SIMPEG agar container yang sah
     * tidak menjadi jalur penyimpanan metadata atau credential provider.
     *
     * @return list<string>
     */
    private static function allowedVariables(): array
    {
        $variables = [];
        $archetypes = array_unique(array_values(WhatsAppTemplateContract::eventTemplateArchetypes()));

        foreach ($archetypes as $archetype) {
            foreach (WhatsAppTemplateContract::requiredVariables($archetype) ?? [] as $variable) {
                $variables[] = $variable;
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * @return array{valid: bool, event_templates: array<string, string>, templates: array<string, array<string, mixed>>}
     */
    private static function empty(bool $valid): array
    {
        return ['valid' => $valid, 'event_templates' => [], 'templates' => []];
    }
}
