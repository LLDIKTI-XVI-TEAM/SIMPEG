<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EwsAlert extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'type',
        'target_date',
        'interval_days',
        'notified_at',
        'is_processed',
    ];

    protected function casts(): array
    {
        return [
            'target_date' => 'date',
            'interval_days' => 'integer',
            'notified_at' => 'datetime',
            'is_processed' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
