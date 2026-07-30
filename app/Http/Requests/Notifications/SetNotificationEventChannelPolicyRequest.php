<?php

namespace App\Http\Requests\Notifications;

use App\Models\RefNotificationChannel;
use App\Services\Notifications\NotificationEventCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Memastikan hanya pasangan event-channel dari katalog domain yang dapat dimutasi Super Admin.
 */
class SetNotificationEventChannelPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'super_admin';
    }

    public function rules(NotificationEventCatalog $catalog): array
    {
        return [
            'event_key' => ['required', 'string', Rule::in(array_keys($catalog->events()))],
            'is_enabled' => ['required', 'boolean'],
            'notification_channel_id' => ['prohibited'],
            'config' => ['prohibited'],
        ];
    }

    public function after(NotificationEventCatalog $catalog): array
    {
        return [
            function (Validator $validator) use ($catalog): void {
                if ($validator->errors()->has('event_key')) {
                    return;
                }

                $channel = $this->route('notificationChannel');
                $eventKey = $this->input('event_key');

                if (! $channel instanceof RefNotificationChannel
                    || ! is_string($eventKey)
                    || ! $catalog->supportsChannel($eventKey, $channel->code)) {
                    $validator->errors()->add('event_key', 'Event tidak mendukung channel notifikasi yang dipilih.');
                }
            },
        ];
    }
}
