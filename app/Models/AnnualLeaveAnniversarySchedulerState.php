<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Checkpoint durable untuk pemerataan window rekonsiliasi anniversary.
 *
 * @property string $scheduler_key
 * @property string|null $cursor_employee_id
 * @property Carbon|null $cursor_advanced_at
 */
final class AnnualLeaveAnniversarySchedulerState extends Model
{
    public const KEY = 'annual_leave_entitlement';

    protected $table = 'annual_leave_anniversary_scheduler_states';

    protected $primaryKey = 'scheduler_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'cursor_employee_id',
        'cursor_advanced_at',
    ];

    protected function casts(): array
    {
        return [
            'cursor_advanced_at' => 'datetime',
        ];
    }
}
