<?php

namespace App\Services\Notifications\WhatsApp;

use JsonException;

/**
 * Menjaga permukaan baca konfigurasi channel WhatsApp bebas credential provider.
 *
 * Access token aktif disimpan sebagai ciphertext khusus dan hanya dibaca runtime.
 * Pemeriksaan dilakukan rekursif agar payload atau konfigurasi legacy tidak
 * menyembunyikan secret di bawah struktur metadata yang tampak tidak berbahaya.
 */
final class WhatsAppConfigSensitiveData
{
    /** @var list<string> */
    private const KEYS = [
        'access_token',
        'access_token_encrypted',
        'refresh_token',
        'token',
        'channel_integration_id',
        'client_secret',
        'app_secret',
        'secret',
        'api_key',
        'x_api_key',
        'bearer_token',
        'authorization',
        'password',
        'private_key',
    ];

    /** @var array<string, true> */
    private const ALLOWED_CONFIGURATION_KEYS = [
        'provider' => true,
        'base_url' => true,
        'canonical_url' => true,
        'template_configuration' => true,
        'configuration_override' => true,
    ];

    /**
     * Memastikan request tidak membawa credential melalui key bersarang.
     * Key diperiksa tanpa membedakan kapitalisasi karena HTTP payload tidak konsisten.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function contains(array $data): bool
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(self::normalizeKey($key), self::KEYS, true)) {
                return true;
            }

            if (is_array($value) && self::contains($value)) {
                return true;
            }

            if (is_string($value) && self::encodedJsonContains($value)) {
                return true;
            }
        }

        return false;
    }

    /** Mendeteksi credential dalam object/array JSON yang disimpan sebagai string metadata. */
    private static function encodedJsonContains(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // Maksimal dua lapis decode menutup JSON string berlapis tanpa membuka
        // rekursi tak terbatas dari metadata yang dikendalikan operator.
        for ($depth = 0; $depth < 2; $depth++) {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return false;
            }

            if (is_array($decoded)) {
                return self::contains($decoded);
            }

            if (! is_string($decoded) || trim($decoded) === '') {
                return false;
            }

            $value = trim($decoded);
        }

        return false;
    }

    /** Menyatukan snake_case, camelCase, kapitalisasi, dan pemisah umum key provider. */
    private static function normalizeKey(string $key): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '_', trim($key)) ?? $key;
        $normalized = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $normalized) ?? $normalized;
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $normalized) ?? $normalized;
        $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;

        return strtolower(trim($normalized, '_'));
    }

    /**
     * Mendeteksi credential yang disisipkan di bawah field form lain.
     * Field legacy top-level tetap diabaikan oleh validated payload agar kontrak
     * form yang sudah ada tidak berubah, tetapi tidak pernah diteruskan ke Action.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function containsNested(array $data): bool
    {
        foreach ($data as $value) {
            if (is_array($value) && self::contains($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mengarantina kontrak yang tidak dapat diverifikasi sebagai object/array JSON
     * aman. String malformed dan scalar hasil decode tidak boleh diteruskan.
     */
    public static function templateConfigurationRequiresQuarantine(mixed $configuration): bool
    {
        if (is_array($configuration)) {
            try {
                $configuration = json_encode($configuration, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return true;
            }
        }

        if (! is_string($configuration) || trim($configuration) === '') {
            return false;
        }

        // Kontrak legacy harus melewati schema yang sama dengan input baru agar field
        // tidak dikenal tidak dapat dirender atau masuk audit sebagai metadata aman.
        $decoded = WhatsAppTemplateConfiguration::decode(
            $configuration,
            array_keys(WhatsAppTemplateContract::eventTemplateArchetypes()),
        );

        return ! $decoded['valid'] || self::contains($decoded);
    }

    /**
     * Menghapus credential legacy dari struktur config sebelum disimpan atau diaudit.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(self::normalizeKey($key), self::KEYS, true)) {
                continue;
            }

            if (is_string($value) && self::encodedJsonContains($value)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? self::scrub($value) : $value;
        }

        return $sanitized;
    }

    /**
     * Mengarantina seluruh kontrak template bila credential ditemukan di dalamnya.
     * Kontrak tidak disensor sebagian karena hasilnya dapat tampak valid tetapi berbeda
     * dari kontrak provider yang disetujui. Key konfigurasi top-level juga dibatasi
     * fail-closed; credential write-only dipulihkan lewat lifecycle khusus caller.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrubConfiguration(array $data): array
    {
        $quarantineTemplate = self::templateConfigurationRequiresQuarantine(
            $data['template_configuration'] ?? null,
        );
        $sanitized = self::scrub($data);

        if ($quarantineTemplate) {
            unset($sanitized['template_configuration']);
        }

        return array_intersect_key($sanitized, self::ALLOWED_CONFIGURATION_KEYS);
    }
}
