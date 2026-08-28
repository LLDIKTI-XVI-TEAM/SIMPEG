<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $idempotency_key
 * @property string $employee_id
 * @property string $event_key
 * @property string $template_key
 * @property string $status
 * @property int $attempt_count
 * @property string|null $failure_code
 * @property Carbon|null $lease_expires_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read WhatsAppNotificationOutbox|null $outbox
 */
class WhatsAppNotificationDelivery extends Model
{
    use HasUuid;

    protected $table = 'whatsapp_notification_deliveries';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'idempotency_key',
        'employee_id',
        'event_key',
        'template_key',
        'status',
        'attempt_count',
        'failure_code',
        'lease_expires_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'lease_expires_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasOne<WhatsAppNotificationOutbox, $this> */
    public function outbox(): HasOne
    {
        return $this->hasOne(WhatsAppNotificationOutbox::class, 'delivery_id');
    }
}
