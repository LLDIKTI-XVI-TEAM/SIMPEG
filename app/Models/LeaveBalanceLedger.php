<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ledger append-only untuk mutasi saldo cuti tahunan dan koreksi yang diaudit.
 *
 * @property int $tahun
 * @property string $event_type
 * @property int $amount
 * @property int|null $source_year
 * @property string|null $sumber_carry_over
 * @property string|null $reason
 * @property string|null $dedup_key
 * @property array<string, mixed>|null $metadata
 * @property string|null $created_by
 */
class LeaveBalanceLedger extends Model
{
    use HasUuid;

    protected $table = 'leave_balance_ledger';

    protected $fillable = [
        'employee_id',
        'leave_request_id',
        'leave_balance_id',
        'tahun',
        'event_type',
        'amount',
        'source_year',
        'sumber_carry_over',
        'reason',
        'dedup_key',
        'metadata',
        'created_by',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'amount' => 'integer',
            'source_year' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /** @return BelongsTo<LeaveBalance, $this> */
    public function leaveBalance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class);
    }
}
