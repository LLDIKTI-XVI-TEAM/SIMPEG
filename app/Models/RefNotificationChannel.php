<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Services\Notifications\WhatsApp\WhatsAppConfigSensitiveData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property bool $is_enabled
 * @property array<string, mixed>|null $config
 */
class RefNotificationChannel extends Model
{
    use HasUuid;

    protected $table = 'ref_notification_channels';

    protected $fillable = [
        'code',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    /**
     * Mencegah credential WhatsApp legacy maupun ciphertext aktif ikut keluar saat
     * model dikonversi menjadi array atau JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $serialized = parent::toArray();

        if (isset($serialized['config']) && is_array($serialized['config'])) {
            $serialized['config'] = WhatsAppConfigSensitiveData::scrubConfiguration($serialized['config']);
        }

        return $serialized;
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /** @return HasMany<NotificationEventChannel, $this> */
    public function eventPolicies(): HasMany
    {
        return $this->hasMany(NotificationEventChannel::class, 'notification_channel_id');
    }
}
