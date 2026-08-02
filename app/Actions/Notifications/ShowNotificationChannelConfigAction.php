<?php

namespace App\Actions\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ShowNotificationChannelConfigAction
{
    private const CHANNELS_PER_PAGE = 12;

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
            ->select(['id', 'code', 'name', 'is_enabled'])
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
            'policies' => $policies,
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
