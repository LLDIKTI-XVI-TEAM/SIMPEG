<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Outbox durable untuk payload job WhatsApp yang terenkripsi.
 *
 * Payload tidak disimpan pada tabel audit delivery agar nomor tujuan, isi variabel,
 * maupun data keputusan tidak dapat terbaca dari permukaan audit operasional.
 *
 * @property string $id
 * @property string $delivery_id
 * @property string $encrypted_payload
 * @property int $publish_attempts
 * @property Carbon|null $publish_attempted_at
 * @property Carbon|null $publish_lease_expires_at
 * @property Carbon|null $published_at
 * @property Carbon|null $publish_failed_at
 * @property string|null $publish_failure_code
 * @property-read WhatsAppNotificationDelivery $delivery
 */
class WhatsAppNotificationOutbox extends Model
{
    use HasUuid;

    protected $table = 'whatsapp_notification_outboxes';

    protected $fillable = [
        'delivery_id',
        'encrypted_payload',
        'publish_attempts',
        'publish_attempted_at',
        'publish_lease_expires_at',
        'published_at',
        'publish_failed_at',
        'publish_failure_code',
    ];

    protected function casts(): array
    {
        return [
            'publish_attempts' => 'integer',
            'publish_attempted_at' => 'datetime',
            'publish_lease_expires_at' => 'datetime',
            'published_at' => 'datetime',
            'publish_failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WhatsAppNotificationDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WhatsAppNotificationDelivery::class, 'delivery_id');
    }
}
