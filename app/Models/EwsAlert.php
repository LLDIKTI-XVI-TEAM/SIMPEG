<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $type
 * @property int $interval_days
 * @property Carbon $target_date
 * @property string $followup_status
 * @property bool|null $is_eligible
 * @property int|null $satyalancana_years
 * @property Carbon|null $notified_at
 * @property Carbon|null $handled_at
 * @property string|null $handled_by
 * @property string|null $handled_note
 * @property-read Employee|null $employee
 */
class EwsAlert extends Model
{
    use HasUuid;

    public const FOLLOWUP_STATUS_ACTIVE = 'aktif';

    public const FOLLOWUP_STATUS_HANDLED = 'ditangani';

    public const FOLLOWUP_STATUS_NOT_NEEDED = 'tidak_perlu';

    public const FOLLOWUP_STATUS_EXPIRED = 'kedaluwarsa';

    protected $fillable = [
        'employee_id',
        'type',
        'target_date',
        'interval_days',
        'notified_at',
        'is_processed',
        'is_eligible',
        'satyalancana_years',
        'followup_status',
        'handled_at',
        'handled_by',
        'handled_note',
    ];

    protected function casts(): array
    {
        return [
            'target_date'        => 'date',
            'interval_days'      => 'integer',
            'notified_at'        => 'datetime',
            'is_processed'       => 'boolean',
            'is_eligible'        => 'boolean',
            'satyalancana_years' => 'integer',
            'handled_at'         => 'datetime',
        ];
    }

    /** @return array<int, string> */
    public static function manualFollowupStatuses(): array
    {
        return [
            self::FOLLOWUP_STATUS_HANDLED,
            self::FOLLOWUP_STATUS_NOT_NEEDED,
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
