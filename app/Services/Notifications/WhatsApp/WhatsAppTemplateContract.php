<?php

namespace App\Services\Notifications\WhatsApp;

final class WhatsAppTemplateContract
{
    public const ARCHETYPE_CUTI_PERLU_TINDAKAN = 'simpeg_cuti_perlu_tindakan';

    public const ARCHETYPE_CUTI_STATUS = 'simpeg_cuti_status';

    public const ARCHETYPE_EWS_PENGINGAT = 'simpeg_ews_pengingat';

    /**
     * Variabel kanonik yang disalurkan ke tombol URL template, bukan ke parameter body.
     * Kontrak resmi provider menempatkan tautan detail pada tombol URL dengan satu parameter.
     */
    public const BUTTON_ONLY_VARIABLE = 'tautan_detail';

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
            // Kontrak resmi provider menambahkan alasan pengajuan pada posisi terakhir body.
            'alasan',
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
     * @param  array<string, mixed>|null  $templateConfig  kontrak template runtime (opsional); bila
     *                                                     tidak diberikan, dibaca dari konfigurasi efektif
     * @return list<string>|null
     */
    public static function requiredVariables(string $templateKey, ?string $archetype = null, ?array $templateConfig = null): ?array
    {
        if (isset(self::REQUIRED_VARIABLES[$templateKey])) {
            return self::REQUIRED_VARIABLES[$templateKey];
        }

        if ($archetype !== null && isset(self::REQUIRED_VARIABLES[$archetype])) {
            return self::REQUIRED_VARIABLES[$archetype];
        }

        $templateConfig ??= app(WhatsAppRuntimeConfig::class)->template($templateKey);
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
     *
     * @param  array<string, mixed>|null  $templateConfig  kontrak template runtime (opsional); bila
     *                                                     tidak diberikan, dibaca dari konfigurasi efektif
     */
    public static function isConfigured(string $templateKey, mixed $templateConfig, ?string $archetype = null): bool
    {
        $resolvedArchetype = $archetype ?? (is_array($templateConfig) ? ($templateConfig['archetype'] ?? null) : null);
        $allowedVariables = self::requiredVariables(
            is_string($resolvedArchetype) ? $resolvedArchetype : $templateKey,
            $archetype,
            is_array($templateConfig) ? $templateConfig : null,
        );
        if ($allowedVariables === null || ! is_array($templateConfig)) {
            return false;
        }

        $templateId = $templateConfig['id'] ?? null;
        $language = $templateConfig['language'] ?? null;
        if (! is_string($templateId) || trim($templateId) === '' || $templateId !== trim($templateId)
            || preg_match('/^\s|\s$/u', $templateId) !== 0
            || ! is_string($language) || trim($language) === '' || $language !== trim($language)
            || preg_match('/^\s|\s$/u', $language) !== 0) {
            return false;
        }

        $variablesMap = $templateConfig['variables_map'] ?? null;
        if (! is_array($variablesMap) || $variablesMap === []) {
            return false;
        }

        // Tautan detail selalu disalurkan lewat tombol URL. Kontrak apa pun (termasuk
        // template split per-event) yang masih menaruhnya di body ditolak agar mapper
        // dan validasi payload worker tidak pernah berbeda pendapat soal isi body.
        if (array_key_exists(self::BUTTON_ONLY_VARIABLE, $variablesMap)) {
            return false;
        }

        $configuredVariables = array_keys($variablesMap);
        if (array_diff($configuredVariables, $allowedVariables) !== []) {
            return false;
        }

        // Archetype bawaan mempertahankan kontrak penuh dokumen submission, dengan
        // pengecualian tautan_detail yang wajib berada pada tombol URL provider.
        // Template split provider boleh hanya meminta subset allowlist archetype.
        if ($templateKey === $resolvedArchetype || isset(self::REQUIRED_VARIABLES[$templateKey])) {
            $expectedVariables = array_values(array_diff($allowedVariables, [self::BUTTON_ONLY_VARIABLE]));
            sort($configuredVariables);
            sort($expectedVariables);
            if ($configuredVariables !== $expectedVariables) {
                return false;
            }
        }

        $providerKeys = [];
        foreach ($variablesMap as $providerKey) {
            if (! is_string($providerKey)
                || trim($providerKey) === ''
                || $providerKey !== trim($providerKey)) {
                return false;
            }

            $providerKeys[] = $providerKey;
        }

        if (count($providerKeys) !== count(array_unique($providerKeys))) {
            return false;
        }

        $button = $templateConfig['button'] ?? null;
        $buttonParameter = is_array($button) ? ($button['parameter'] ?? null) : null;

        return is_array($button)
            && ($button['type'] ?? null) === 'url'
            && is_string($buttonParameter)
            && trim($buttonParameter) !== ''
            && $buttonParameter === trim($buttonParameter);
    }

    /**
     * Memastikan payload job tetap cocok dengan kontrak provider saat worker benar-benar berjalan.
     *
     * @param  array<int|string, string>  $bodyVariables
     * @param  array<int|string, string>  $buttonVariables
     * @param  array<string, mixed>|null  $templateConfig  kontrak template runtime (opsional); bila
     *                                                     tidak diberikan, dibaca dari konfigurasi efektif
     * @param  string|null  $canonicalUrl  domain resmi runtime (opsional); bila tidak diberikan,
     *                                     dibaca dari konfigurasi efektif
     * @param  string|null  $archetype  bentuk data domain untuk template split per-event
     */
    public static function matchesQueuedPayload(
        string $templateKey,
        string $templateId,
        string $language,
        array $bodyVariables,
        array $buttonVariables,
        ?array $queuedVariablesMap = null,
        ?array $templateConfig = null,
        ?string $canonicalUrl = null,
        ?string $archetype = null,
    ): bool {
        $templateConfig ??= app(WhatsAppRuntimeConfig::class)->template($templateKey);
        if (! self::isConfigured($templateKey, $templateConfig, $archetype)) {
            return false;
        }

        /** @var array{id: string, language: string, variables_map: array<string, string>, button: array{type: string, parameter: string}} $templateConfig */
        if (! hash_equals($templateConfig['id'], $templateId)
            || ! hash_equals($templateConfig['language'], $language)) {
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

        $canonicalUrl ??= app(WhatsAppRuntimeConfig::class)->canonicalUrl();
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
