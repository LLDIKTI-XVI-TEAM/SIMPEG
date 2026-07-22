<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $event_key
 * @property string $notification_channel_id
 * @property bool $is_enabled
 * @property-read RefNotificationChannel $channel
 */
class NotificationEventChannel extends Model
{
    use HasUuid;

    protected $fillable = [
        'event_key',
        'notification_channel_id',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<RefNotificationChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(RefNotificationChannel::class, 'notification_channel_id');
    }
}
