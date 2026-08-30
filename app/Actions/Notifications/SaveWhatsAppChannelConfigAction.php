<?php

namespace App\Actions\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\AuditService;
use App\Services\Notifications\WhatsApp\WhatsAppAccessTokenCipher;
use App\Services\Notifications\WhatsApp\WhatsAppConfigSensitiveData;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateConfiguration;
use App\Services\Notifications\WhatsApp\WhatsAppTemplateContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Menyimpan konfigurasi Qontak ke setting aplikasi (kolom config channel whatsapp_business).
 * Access token dipersist terenkripsi, sedangkan Channel Integration ID disimpan
 * write-only dan selalu dimasking dari audit. Field teks non-rahasia yang
 * dikosongkan berarti menghapus nilai terkait.
 */
class SaveWhatsAppChannelConfigAction
{
    public function execute(string $channelId, array $data, Request $request): RefNotificationChannel
    {
        return DB::transaction(function () use ($channelId, $data, $request): RefNotificationChannel {
            $channel = RefNotificationChannel::query()->lockForUpdate()->findOrFail($channelId);

            // Konfigurasi provider hanya berlaku untuk channel WhatsApp Business.
            if ($channel->code !== WhatsAppRuntimeConfig::CHANNEL_CODE) {
                abort(404);
            }

            $contract = trim((string) ($data['template_configuration'] ?? ''));
            if ($contract !== '') {
                $this->assertContractValid($contract);
            }

            $channelIntegrationId = trim((string) ($data['channel_integration_id'] ?? ''));
            $clearChannelIntegrationId = (bool) ($data['clear_channel_integration_id'] ?? false);
            $this->assertChannelIntegrationIdMutationValid($channelIntegrationId, $clearChannelIntegrationId);

            $oldConfig = is_array($channel->config) ? $channel->config : [];
            $oldCiphertext = $oldConfig[WhatsAppAccessTokenCipher::CONFIG_KEY] ?? null;
            $oldChannelIntegrationId = $this->validChannelIntegrationId(
                $oldConfig['channel_integration_id'] ?? null,
            );
            // Bersihkan plaintext/ciphertext legacy sebelum hanya ciphertext terkontrol
            // yang dipertahankan atau dibuat ulang melalui lifecycle write-only.
            $newConfig = WhatsAppConfigSensitiveData::scrubConfiguration($oldConfig);
            $accessTokenStatus = 'unchanged';
            $channelIntegrationIdStatus = 'unchanged';

            $accessToken = trim((string) ($data['access_token'] ?? ''));
            if ($accessToken !== '') {
                $newConfig[WhatsAppAccessTokenCipher::CONFIG_KEY] = WhatsAppAccessTokenCipher::encrypt($accessToken);
                $accessTokenStatus = is_string($oldCiphertext) && trim($oldCiphertext) !== ''
                    ? 'rotated'
                    : 'added';
            } elseif ((bool) ($data['clear_access_token'] ?? false)) {
                $accessTokenStatus = is_string($oldCiphertext) && trim($oldCiphertext) !== ''
                    ? 'cleared'
                    : 'unchanged';
            } elseif (is_string($oldCiphertext) && trim($oldCiphertext) !== '') {
                // Field kosong adalah intent mempertahankan token; ciphertext lama
                // disalin tanpa dekripsi agar plaintext tidak melewati Action.
                $newConfig[WhatsAppAccessTokenCipher::CONFIG_KEY] = $oldCiphertext;
            }

            if ($channelIntegrationId !== '') {
                $newConfig['channel_integration_id'] = $channelIntegrationId;
                $channelIntegrationIdStatus = $oldChannelIntegrationId === null
                    ? 'added'
                    : ($oldChannelIntegrationId === $channelIntegrationId ? 'unchanged' : 'rotated');
            } elseif ($clearChannelIntegrationId) {
                $channelIntegrationIdStatus = $oldChannelIntegrationId === null ? 'unchanged' : 'cleared';
            } elseif ($oldChannelIntegrationId !== null) {
                // Input kosong mempertahankan ID tanpa memantulkannya kembali ke form.
                $newConfig['channel_integration_id'] = $oldChannelIntegrationId;
            }

            foreach (['base_url', 'canonical_url'] as $key) {
                $value = trim((string) ($data[$key] ?? ''));
                if ($value === '') {
                    unset($newConfig[$key]);

                    continue;
                }

                $newConfig[$key] = $value;
            }

            if ($contract === '') {
                unset($newConfig['template_configuration']);
            } else {
                $newConfig['template_configuration'] = $contract;
            }

            if ($newConfig === []) {
                // Marker non-rahasia membedakan override kosong operator dari channel
                // yang belum memiliki setting, sehingga baseline environment tidak aktif kembali.
                $newConfig['configuration_override'] = true;
            }

            // Provider ditetapkan oleh aplikasi karena adapter terikat ke Qontak;
            // artefak yang dikosongkan operator tetap membuat readiness fail-closed.
            if ($newConfig !== []) {
                $newConfig['provider'] = 'qontak';
            }

            $channel->forceFill(['config' => $newConfig])->save();

            AuditService::logOrFail(
                'CONFIG_UPDATE',
                'RefNotificationChannel',
                $channel->id,
                $this->auditConfig($oldConfig),
                $this->auditConfig($newConfig, $accessTokenStatus, $channelIntegrationIdStatus),
                $request,
            );

            return $channel;
        });
    }

    /**
     * Menjaga Action tetap fail-closed untuk caller non-HTTP tanpa menggantikan
     * validasi FormRequest sebagai guard utama request dan sanitasi old input.
     */
    private function assertContractValid(string $contract): void
    {
        $decoded = WhatsAppTemplateConfiguration::decode(
            $contract,
            array_keys(WhatsAppTemplateContract::eventTemplateArchetypes()),
        );

        if (! $decoded['valid']) {
            throw ValidationException::withMessages([
                'template_configuration' => 'Kontrak template WhatsApp tidak valid. Wajib berupa JSON berisi event_templates dan templates.',
            ]);
        }

        if (WhatsAppConfigSensitiveData::templateConfigurationRequiresQuarantine($contract)) {
            throw ValidationException::withMessages([
                'template_configuration' => 'Kontrak template WhatsApp tidak boleh memuat credential provider.',
            ]);
        }
    }

    /** Menjaga caller non-HTTP tidak dapat menyimpan ID malformed atau intent konflik. */
    private function assertChannelIntegrationIdMutationValid(string $value, bool $clear): void
    {
        if ($value !== '' && (! Str::isUuid($value) || $clear)) {
            throw ValidationException::withMessages([
                'channel_integration_id' => $clear
                    ? 'Channel Integration ID baru dan penghapusannya tidak dapat dipilih bersamaan.'
                    : 'Channel Integration ID wajib berupa UUID yang valid.',
            ]);
        }
    }

    /** Mengabaikan ID legacy malformed agar tidak dapat hidup kembali saat penyimpanan ulang. */
    private function validChannelIntegrationId(mixed $value): ?string
    {
        $normalized = is_string($value) ? trim($value) : '';

        return $normalized !== '' && Str::isUuid($normalized) ? $normalized : null;
    }

    /**
     * Jangan pernah simpan artefak sensitif legacy ke payload audit konfigurasi.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function auditConfig(
        array $config,
        ?string $accessTokenStatus = null,
        ?string $channelIntegrationIdStatus = null,
    ): array {
        $auditConfig = WhatsAppConfigSensitiveData::scrubConfiguration($config);

        if ($accessTokenStatus !== null) {
            $auditConfig['access_token_status'] = $accessTokenStatus;
        }

        if ($channelIntegrationIdStatus !== null) {
            $auditConfig['channel_integration_id_status'] = $channelIntegrationIdStatus;
        }

        return $auditConfig;
    }
}
