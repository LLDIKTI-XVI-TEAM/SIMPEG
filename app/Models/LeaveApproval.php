<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_id');
    }
}
