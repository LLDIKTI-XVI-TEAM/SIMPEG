<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property string $user_id Employee penerima pada fakta domain legacy
 * @property string|null $recipient_user_id User penerima inbox
 * @property string|null $ews_alert_id
 * @property array<string, mixed>|null $data
 * @property bool $is_read
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class SimpegNotification extends Model
{
    use HasUuid;

    protected $table = 'notifications';

    protected $fillable = [
        'user_id',
        'recipient_user_id',
        'ews_alert_id',
        'type',
        'title',
        'body',
        'data',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'user_id');
    }

    /** Penerima inbox; berbeda dari employee konteks fakta pada kolom user_id legacy. */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    protected static function booted(): void
    {
        static::creating(function (self $notification): void {
            if ($notification->recipient_user_id !== null || $notification->user_id === null) {
                return;
            }

            $users = User::query()
                ->where('employee_id', $notification->user_id)
                ->orderBy('id')
                ->limit(2)
                ->get(['id']);

            if ($users->count() === 1) {
                $notification->recipient_user_id = $users->first()->id;
            }
        });
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    /**
     * Bentuk respons API inbox; hanya memuat data notifikasi milik penerima.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'is_read' => $this->is_read,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
