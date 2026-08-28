<?php

namespace App\Services\Notifications\WhatsApp;

final class WhatsAppTemplateContract
{
    public const ARCHETYPE_CUTI_PERLU_TINDAKAN = 'simpeg_cuti_perlu_tindakan';

    public const ARCHETYPE_CUTI_STATUS = 'simpeg_cuti_status';

    public const ARCHETYPE_EWS_PENGINGAT = 'simpeg_ews_pengingat';

    /**
     * Pemetaan internal event ke archetype hanya mendefinisikan bentuk data SIMPEG.
     * ID, bahasa, urutan variabel, dan tombol tetap wajib datang dari kontrak provider runtime.
     *
     * @var array<string, string>
     */
    public const EVENT_TEMPLATE_ARCHETYPES = [
        'cuti.pengajuan_baru' => self::ARCHETYPE_CUTI_PERLU_TINDAKAN,
        'cuti.menunggu_persetujuan' => self::ARCHETYPE_CUTI_PERLU_TINDAKAN,
        'cuti.disetujui' => self::ARCHETYPE_CUTI_STATUS,
        'cuti.ditunda' => self::ARCHETYPE_CUTI_STATUS,
        'cuti.perlu_perubahan' => self::ARCHETYPE_CUTI_STATUS,
        'cuti.tidak_disetujui' => self::ARCHETYPE_CUTI_STATUS,
        'cuti.ditangguhkan_tugas_dinas' => self::ARCHETYPE_CUTI_STATUS,
        'cuti.dikembalikan_karena_rollover' => self::ARCHETYPE_CUTI_STATUS,
        'ews.kenaikan_pangkat' => self::ARCHETYPE_EWS_PENGINGAT,
        'ews.kgb' => self::ARCHETYPE_EWS_PENGINGAT,
        'ews.pensiun' => self::ARCHETYPE_EWS_PENGINGAT,
        'ews.kontrak_pppk' => self::ARCHETYPE_EWS_PENGINGAT,
        'ews.satyalancana' => self::ARCHETYPE_EWS_PENGINGAT,
    ];

    /** @return array<string, string> */
    public static function eventTemplateArchetypes(): array
    {
        return self::EVENT_TEMPLATE_ARCHETYPES;
    }

    /**
     * @var array<string, list<string>>
     */
    private const REQUIRED_VARIABLES = [
        self::ARCHETYPE_CUTI_PERLU_TINDAKAN => [
            'nama_pegawai',
            'jenis_cuti',
            'tanggal_mulai',
            'tanggal_selesai',
            'jumlah_hari',
            'tautan_detail',
        ],
        self::ARCHETYPE_CUTI_STATUS => [
            'nama_pegawai',
            'jenis_cuti',
            'status',
            'keterangan',
            'tautan_detail',
        ],
        self::ARCHETYPE_EWS_PENGINGAT => [
            'nama_pegawai',
            'jenis_peringatan',
            'tanggal_target',
            'sisa_waktu',
            'tautan_detail',
        ],
    ];

    /**
     * @return list<string>|null
     */
    public static function requiredVariables(string $templateKey, ?string $archetype = null): ?array
    {
        if (isset(self::REQUIRED_VARIABLES[$templateKey])) {
            return self::REQUIRED_VARIABLES[$templateKey];
        }

        if ($archetype !== null && isset(self::REQUIRED_VARIABLES[$archetype])) {
            return self::REQUIRED_VARIABLES[$archetype];
        }

        $templateConfig = config("services.whatsapp.templates.{$templateKey}");
        if (is_array($templateConfig)) {
            $configuredArchetype = $templateConfig['archetype'] ?? null;
            if (is_string($configuredArchetype) && isset(self::REQUIRED_VARIABLES[$configuredArchetype])) {
                return self::REQUIRED_VARIABLES[$configuredArchetype];
            }

            if (isset($templateConfig['required_variables']) && is_array($templateConfig['required_variables'])) {
                /** @var list<string> $variables */
                $variables = array_values($templateConfig['required_variables']);

                return $variables;
            }
        }

        return null;
    }

    /**
     * Provider harus memberikan kontrak runtime lengkap. Tidak ada fallback ke nama variabel proposal.
     */
    public static function isConfigured(string $templateKey, mixed $templateConfig, ?string $archetype = null): bool
    {
        $resolvedArchetype = $archetype ?? (is_array($templateConfig) ? ($templateConfig['archetype'] ?? null) : null);
        $allowedVariables = self::requiredVariables(
            is_string($resolvedArchetype) ? $resolvedArchetype : $templateKey,
        );
        if ($allowedVariables === null || ! is_array($templateConfig)) {
            return false;
        }

        $templateId = $templateConfig['id'] ?? null;
        $language = $templateConfig['language'] ?? null;
        if (! is_string($templateId) || trim($templateId) === ''
            || ! is_string($language) || trim($language) === '') {
            return false;
        }

        $variablesMap = $templateConfig['variables_map'] ?? null;
        if (! is_array($variablesMap) || $variablesMap === []) {
            return false;
        }

        $configuredVariables = array_keys($variablesMap);
        if (array_diff($configuredVariables, $allowedVariables) !== []) {
            return false;
        }

        // Archetype bawaan mempertahankan kontrak penuh dokumen submission.
        // Template split provider boleh hanya meminta subset allowlist archetype.
        if ($templateKey === $resolvedArchetype || isset(self::REQUIRED_VARIABLES[$templateKey])) {
            sort($configuredVariables);
            $expectedVariables = $allowedVariables;
            sort($expectedVariables);
            if ($configuredVariables !== $expectedVariables) {
                return false;
            }
        }

        $providerKeys = [];
        foreach ($variablesMap as $providerKey) {
            if (! is_string($providerKey) || trim($providerKey) === '') {
                return false;
            }

            $providerKeys[] = trim($providerKey);
        }

        if (count($providerKeys) !== count(array_unique($providerKeys))) {
            return false;
        }

        $button = $templateConfig['button'] ?? null;

        return is_array($button)
            && ($button['type'] ?? null) === 'url'
            && is_string($button['parameter'] ?? null)
            && trim($button['parameter']) !== '';
    }

    /**
     * Memastikan payload job tetap cocok dengan kontrak provider saat worker benar-benar berjalan.
     *
     * @param  array<int|string, string>  $bodyVariables
     * @param  array<int|string, string>  $buttonVariables
     */
    public static function matchesQueuedPayload(
        string $templateKey,
        string $templateId,
        string $language,
        array $bodyVariables,
        array $buttonVariables,
        ?array $queuedVariablesMap = null,
    ): bool {
        $templateConfig = config("services.whatsapp.templates.{$templateKey}");
        if (! self::isConfigured($templateKey, $templateConfig)) {
            return false;
        }

        /** @var array{id: string, language: string, variables_map: array<string, string>, button: array{type: string, parameter: string}} $templateConfig */
        if (! hash_equals(trim($templateConfig['id']), trim($templateId))
            || ! hash_equals(trim($templateConfig['language']), trim($language))) {
            return false;
        }

        if ($queuedVariablesMap === null || $queuedVariablesMap !== $templateConfig['variables_map']) {
            return false;
        }

        $expectedBodyKeys = array_values($templateConfig['variables_map']);
        $actualBodyKeys = array_map(static fn (int|string $key): string => (string) $key, array_keys($bodyVariables));
        sort($expectedBodyKeys);
        sort($actualBodyKeys);
        if ($actualBodyKeys !== $expectedBodyKeys) {
            return false;
        }

        foreach ($bodyVariables as $value) {
            if (! is_string($value)) {
                return false;
            }
        }

        $expectedButtonKey = $templateConfig['button']['parameter'];

        $actualButtonKeys = array_map(static fn (int|string $key): string => (string) $key, array_keys($buttonVariables));

        if ($actualButtonKeys !== [$expectedButtonKey]) {
            return false;
        }

        $buttonUrl = $buttonVariables[$expectedButtonKey] ?? null;
        if (! is_string($buttonUrl) || trim($buttonUrl) === '') {
            return false;
        }

        $canonicalUrl = config('services.whatsapp.canonical_url');
        if (! is_string($canonicalUrl) || trim($canonicalUrl) === '') {
            return false;
        }

        $parsedCanonical = parse_url(trim($canonicalUrl));
        $canonicalScheme = strtolower((string) ($parsedCanonical['scheme'] ?? ''));
        $canonicalHost = strtolower((string) ($parsedCanonical['host'] ?? ''));
        $canonicalPort = $parsedCanonical['port'] ?? null;
        if ($canonicalScheme !== 'https' || $canonicalHost === '' || in_array($canonicalHost, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        $parsedButton = parse_url(trim($buttonUrl));
        $buttonScheme = strtolower((string) ($parsedButton['scheme'] ?? ''));
        $buttonHost = strtolower((string) ($parsedButton['host'] ?? ''));
        $buttonPort = $parsedButton['port'] ?? null;

        return $buttonScheme === 'https' && $buttonHost === $canonicalHost && $buttonPort === $canonicalPort;
    }
}
