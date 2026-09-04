<?php

namespace App\Actions\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\Notifications\NotificationEventCatalog;
use App\Services\Notifications\WhatsApp\WhatsAppAccessTokenCipher;
use App\Services\Notifications\WhatsApp\WhatsAppConfigSensitiveData;
use App\Services\Notifications\WhatsApp\WhatsAppRuntimeConfig;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use JsonException;

class ShowNotificationChannelConfigAction
{
    private const CHANNELS_PER_PAGE = 10;

    /** @var list<string> */
    private const CORE_CHANNEL_CODES = ['in_app', 'email', 'whatsapp_business'];

    public function __construct(private readonly NotificationEventCatalog $catalog) {}

    /**
     * Menyiapkan payload SSR terbatas tanpa config rahasia dan tanpa query lanjutan dari Blade.
     *
     * @return array{
     *     channels:list<array{
     *         id:string,
     *         code:string,
     *         name:string,
     *         is_enabled:bool,
     *         adapter_available:bool,
     *         is_core:bool,
     *         policies:array<string, array{supported:bool,raw_enabled:bool,effective_enabled:bool}>
     *     }>,
     *     eventGroups:array<string, list<array{key:string,label:string}>>,
     *     channelPaginator:LengthAwarePaginator
     * }
     */
    public function execute(): array
    {
        $events = $this->catalog->events();
        $channelPaginator = RefNotificationChannel::query()
            ->select(['id', 'code', 'name', 'is_enabled', 'config'])
            ->with('eventPolicies:id,event_key,notification_channel_id,is_enabled')
            // Channel inti selalu berada di halaman pertama agar kill-switch utama mudah dijangkau.
            ->orderByRaw("case code when 'in_app' then 0 when 'email' then 1 when 'whatsapp_business' then 2 else 3 end")
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::CHANNELS_PER_PAGE)
            ->withQueryString()
            ->through(fn (RefNotificationChannel $channel): array => $this->channelPayload($channel, $events));

        return [
            'channels' => $channelPaginator->items(),
            'eventGroups' => $this->eventGroups($events),
            'channelPaginator' => $channelPaginator,
        ];
    }

    /**
     * @param  array<string, array{label:string,group:string,allowed_channels:list<string>}>  $events
     * @return array{
     *     id:string,
     *     code:string,
     *     name:string,
     *     is_enabled:bool,
     *     adapter_available:bool,
     *     is_core:bool,
     *     whatsapp_config:array{base_url:string|null,canonical_url:string|null,template_configuration:string|null,access_token_configured:bool,channel_integration_id_configured:bool}|null,
     *     policies:array<string, array{supported:bool,raw_enabled:bool,effective_enabled:bool}>
     * }
     */
    private function channelPayload(RefNotificationChannel $channel, array $events): array
    {
        $rawPolicies = $channel->eventPolicies->keyBy('event_key');
        $policies = [];

        foreach ($events as $eventKey => $event) {
            $rawEnabled = (bool) ($rawPolicies->get($eventKey)?->is_enabled ?? false);

            $policies[$eventKey] = [
                'supported' => in_array($channel->code, $event['allowed_channels'], true),
                'raw_enabled' => $rawEnabled,
                'effective_enabled' => $channel->is_enabled && $rawEnabled,
            ];
        }

        return [
            'id' => $channel->id,
            'code' => $channel->code,
            'name' => $channel->name,
            'is_enabled' => $channel->is_enabled,
            'adapter_available' => $this->catalog->hasAdapter($channel->code),
            'is_core' => in_array($channel->code, self::CORE_CHANNEL_CODES, true),
            // Ringkasan konfigurasi WhatsApp hanya memuat artefak non-rahasia.
            'whatsapp_config' => $channel->code === WhatsAppRuntimeConfig::CHANNEL_CODE
                ? $this->whatsappConfigSummary($channel)
                : null,
            'policies' => $policies,
        ];
    }

    /**
     * @return array{base_url:string|null,canonical_url:string|null,template_configuration:string|null,access_token_configured:bool,channel_integration_id_configured:bool}
     */
    private function whatsappConfigSummary(RefNotificationChannel $channel): array
    {
        // JSON scalar legacy dikarantina agar halaman pengaturan tetap dapat dibuka untuk pemulihan.
        $rawConfig = is_array($channel->config) ? $channel->config : [];
        // Ciphertext rusak tidak boleh dilaporkan sebagai credential yang dapat dipakai runtime.
        $accessTokenConfigured = WhatsAppAccessTokenCipher::decrypt(
            $rawConfig[WhatsAppAccessTokenCipher::CONFIG_KEY] ?? null,
        ) !== null;
        $channelIntegrationId = $rawConfig['channel_integration_id'] ?? null;
        $channelIntegrationIdConfigured = is_string($channelIntegrationId)
            && Str::isUuid(trim($channelIntegrationId));
        $config = WhatsAppConfigSensitiveData::scrubConfiguration($rawConfig);

        $stringOrNull = static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
            ? $value
            : null;

        // Kontrak boleh tersimpan sebagai array; textarea harus tetap menampilkan JSON
        // agar penyimpanan ulang form tidak menghapus kontrak yang sebelumnya valid.
        $contract = $config['template_configuration'] ?? null;
        if (is_array($contract)) {
            try {
                $contract = json_encode($contract, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                $contract = null;
            }
        }

        return [
            'base_url' => $stringOrNull($config['base_url'] ?? null),
            'canonical_url' => $stringOrNull($config['canonical_url'] ?? null),
            'template_configuration' => $stringOrNull($contract),
            'access_token_configured' => $accessTokenConfigured,
            'channel_integration_id_configured' => $channelIntegrationIdConfigured,
        ];
    }

    /**
     * @param  array<string, array{label:string,group:string,allowed_channels:list<string>}>  $events
     * @return array<string, list<array{key:string,label:string}>>
     */
    private function eventGroups(array $events): array
    {
        $groups = [];

        foreach ($events as $eventKey => $event) {
            $groups[$event['group']][] = [
                'key' => $eventKey,
                'label' => $event['label'],
            ];
        }

        return $groups;
    }
}
