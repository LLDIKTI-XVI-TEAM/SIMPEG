<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $stage
 * @property string $action
 * @property Carbon|null $acted_at
 * @property-read Employee|null $approver
 */
class LeaveApproval extends Model
{
    use HasUuid;

    protected $fillable = [
        'leave_request_id',
        'approver_id',
        'stage',
        'action',
        'komentar',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'stage' => 'integer',
            'acted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_id');
    }
}
