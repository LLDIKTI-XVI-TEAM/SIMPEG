<?php

namespace App\Services\Notifications\WhatsApp;

use App\Models\RefNotificationChannel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sumber kebenaran artefak runtime WhatsApp Business.
 *
 * Seluruh artefak Qontak dimuat dari setting aplikasi (kolom config pada
 * ref_notification_channels untuk kode whatsapp_business). Access token disimpan
 * terenkripsi; Channel Integration ID dibaca hanya dari nilai UUID resmi di setting.
 *
 * Struktur setting yang didukung:
 * - provider                  : nama provider (mis. "qontak")
 * - base_url                  : base URL API provider
 * - canonical_url             : domain resmi SIMPEG untuk tautan tombol template
 * - access_token_encrypted    : ciphertext write-only access token Qontak
 * - channel_integration_id    : UUID channel provider yang disimpan write-only
 * - template_configuration    : JSON kontrak resmi provider (event_templates + templates)
 */
final class WhatsAppRuntimeConfig implements WhatsAppRuntimeConfiguration
{
    public const CHANNEL_CODE = 'whatsapp_business';

    /**
     * Bacaan setting aplikasi di-memo dalam jangka pendek agar pengiriman berantai
     * (scheduler EWS, batch queue) tidak membaca baris channel untuk setiap dispatch.
     * Perubahan setting tetap berlaku paling lambat selama masa TTL ini; kill-switch
     * operasional (is_enabled channel dan kebijakan event) tetap dibaca langsung
     * setiap dispatch sehingga tidak ikut tertunda.
     */
    private const SETTINGS_TTL_SECONDS = 60;

    private ?array $cachedSettings = null;

    private float $cachedSettingsAt = 0.0;

    /**
     * Mengembalikan konfigurasi efektif dengan bentuk sama seperti config('services.whatsapp').
     *
     * Setting database selalu menjadi satu-satunya sumber artefak provider. Field yang
     * tidak ada menimpa baseline dengan nilai kosong sehingga penghapusan oleh operator
     * benar-benar fail-closed dan tidak jatuh kembali ke environment lama.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->effectiveConfig($this->settings());
    }

    /**
     * Membuang memo setting agar bacaan berikutnya langsung dari database.
     * Dipakai worker untuk memastikan mismatch kontrak bukan akibat cache basi
     * sebelum delivery ditandai skip terminal.
     */
    public function invalidate(): void
    {
        $this->cachedSettings = null;
        $this->cachedSettingsAt = 0.0;
    }

    /**
     * @return array<string, string> kontrak template event => kunci template
     */
    public function eventTemplates(): array
    {
        $eventTemplates = $this->all()['event_templates'] ?? [];

        return is_array($eventTemplates) ? $eventTemplates : [];
    }

    /**
     * @return array<string, mixed>|null kontrak satu template resmi provider
     */
    public function template(string $templateKey): ?array
    {
        $templates = $this->all()['templates'] ?? [];
        $template = is_array($templates) ? ($templates[$templateKey] ?? null) : null;

        return is_array($template) ? $template : null;
    }

    public function canonicalUrl(): ?string
    {
        $canonicalUrl = $this->all()['canonical_url'] ?? null;

        return is_string($canonicalUrl) && trim($canonicalUrl) !== '' ? $canonicalUrl : null;
    }

    /**
     * Token akses API dalam bentuk plaintext hanya untuk kebutuhan pengiriman;
     * jangan pernah dicatat ke log maupun audit.
     */
    public function accessToken(): ?string
    {
        return $this->providerCredentials()['access_token'];
    }

    /**
     * Membaca konfigurasi efektif dan credential dari satu versi baris database.
     * Snapshot ini juga menyegarkan memo non-rahasia agar validasi worker berikutnya
     * tidak membandingkan payload terhadap konfigurasi lama.
     *
     * @return array{config: array<string, mixed>, access_token: ?string, channel_integration_id: ?string}
     */
    public function providerSnapshot(): array
    {
        $databaseConfig = $this->databaseConfig();
        $settings = $this->sanitizedSettings($databaseConfig);
        $this->rememberSettings($settings);

        return [
            'config' => $this->effectiveConfig($settings),
            'access_token' => WhatsAppAccessTokenCipher::decrypt(
                $databaseConfig[WhatsAppAccessTokenCipher::CONFIG_KEY] ?? null,
            ),
            'channel_integration_id' => $this->validChannelIntegrationId(
                $databaseConfig['channel_integration_id'] ?? null,
            ),
        ];
    }

    /**
     * Membaca token dan identitas channel dari satu snapshot database agar rotasi
     * atau penghapusan atomik tidak pernah dipasangkan dengan credential cache lama.
     *
     * @return array{access_token: ?string, channel_integration_id: ?string}
     */
    public function providerCredentials(): array
    {
        $snapshot = $this->providerSnapshot();

        return [
            'access_token' => $snapshot['access_token'],
            'channel_integration_id' => $snapshot['channel_integration_id'],
        ];
    }

    /**
     * @return array<string, mixed> isi kolom config channel whatsapp_business
     */
    private function settings(): array
    {
        if ($this->cachedSettings !== null
            && (microtime(true) - $this->cachedSettingsAt) < self::SETTINGS_TTL_SECONDS) {
            return $this->cachedSettings;
        }

        $settings = $this->sanitizedSettings($this->databaseConfig());
        $this->rememberSettings($settings);

        return $settings;
    }

    /** @return array<string, mixed> */
    private function databaseConfig(): array
    {
        try {
            $config = RefNotificationChannel::query()
                ->where('code', self::CHANNEL_CODE)
                ->value('config');
        } catch (QueryException) {
            // Tabel channel belum tersedia (mis. saat migrasi awal); pakai setting
            // kosong sehingga readiness tetap menilai artefak sebagai belum terpenuhi.
            return [];
        }

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<string, mixed>  $databaseConfig
     * @return array<string, mixed>
     */
    private function sanitizedSettings(array $databaseConfig): array
    {
        // Runtime non-rahasia tidak boleh menghidupkan kembali kontrak legacy yang
        // menyisipkan credential di JSON. Credential provider dibaca lewat accessor khusus.
        $settings = WhatsAppConfigSensitiveData::scrubConfiguration($databaseConfig);

        // ID hanya dipulihkan dari key top-level tervalidasi. Nilai nested atau
        // malformed tetap dibuang oleh quarantine agar tidak mencapai adapter.
        $channelIntegrationId = $this->validChannelIntegrationId(
            $databaseConfig['channel_integration_id'] ?? null,
        );
        if ($channelIntegrationId !== null) {
            $settings['channel_integration_id'] = $channelIntegrationId;
        }

        return $settings;
    }

    /** @param  array<string, mixed>  $settings */
    private function rememberSettings(array $settings): void
    {
        $this->cachedSettings = $settings;
        $this->cachedSettingsAt = microtime(true);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function effectiveConfig(array $settings): array
    {
        $merged = config('services.whatsapp', []);

        foreach (['provider', 'base_url', 'canonical_url', 'channel_integration_id'] as $key) {
            $value = $settings[$key] ?? null;
            $merged[$key] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }

        // Credential sengaja tidak dimasukkan ke array generik. Consumer yang berhak
        // harus memakai accessor khusus agar plaintext memiliki batas akses eksplisit.
        unset($merged['access_token']);

        $contract = $this->resolveContract($settings);
        if ($contract !== null) {
            // Kontrak dari setting menggantikan kontrak baseline secara utuh agar tidak
            // tercampur dua sumber kebenaran. Kontrak tidak valid mematikan seluruh template (fail-closed).
            $merged['template_configuration'] = $contract['raw'];
            $merged['runtime_configuration_valid'] = $contract['valid'];
            $merged['event_templates'] = $contract['valid'] ? $contract['event_templates'] : [];
            $merged['templates'] = $contract['valid'] ? $contract['templates'] : [];
        } else {
            // Kontrak yang dihapus dari setting tidak boleh digantikan kontrak baseline.
            $merged['template_configuration'] = null;
            $merged['runtime_configuration_valid'] = false;
            $merged['event_templates'] = [];
            $merged['templates'] = [];
        }

        return $merged;
    }

    /** Menolak identitas channel legacy yang bukan UUID resmi provider. */
    private function validChannelIntegrationId(mixed $value): ?string
    {
        $normalized = is_string($value) ? trim($value) : '';

        return $normalized !== '' && Str::isUuid($normalized) ? $normalized : null;
    }

    /**
     * Menguraikan kontrak template resmi dari setting. Menerima string JSON maupun array
     * hasil decode, dengan aturan validasi sama seperti konfigurasi runtime environment.
     *
     * @return array{raw: string, valid: bool, event_templates: array<string, string>, templates: array<string, mixed>}|null
     */
    private function resolveContract(array $settings): ?array
    {
        $configuration = $settings['template_configuration'] ?? null;

        if (is_array($configuration)) {
            try {
                $raw = json_encode($configuration, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return ['raw' => '', 'valid' => false, 'event_templates' => [], 'templates' => []];
            }
        } elseif (is_string($configuration) && trim($configuration) !== '') {
            $raw = $configuration;
        } else {
            return null;
        }

        $decoded = WhatsAppTemplateConfiguration::decode(
            $raw,
            array_keys(WhatsAppTemplateContract::eventTemplateArchetypes()),
        );

        return [
            'raw' => $raw,
            'valid' => $decoded['valid'],
            'event_templates' => $decoded['event_templates'],
            'templates' => $decoded['templates'],
        ];
    }
}
