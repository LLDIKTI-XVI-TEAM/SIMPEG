<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

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
 * @property CarbonInterface|null $occurred_at
 */
class LeaveBalanceLedger extends Model
{
    use HasUuid;

    public const EVENT_ANNUAL_ENTITLEMENT_GRANTED = 'annual_entitlement_granted';

    public const EVENT_CARRY_OVER_EXPIRED = 'carry_over_expired';

    public const EVENT_CARRY_OVER_GRANTED = 'carry_over_granted';

    public const EVENT_DUTY_POSTPONEMENT_RECORDED = 'duty_postponement_recorded';

    public const EVENT_LEAVE_DEDUCTED = 'leave_deducted';

    public const EVENT_MANUAL_ADJUSTMENT = 'manual_adjustment';

    public const EVENT_OPENING_BALANCE_SET = 'opening_balance_set';

    public const EVENT_ROLLOVER_APPLIED = 'rollover_applied';

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

    /**
     * Event resmi menjaga ledger hanya mencatat mutasi saldo berbasis hari, bukan uang atau kompensasi.
     *
     * @return list<string>
     */
    public static function eventTypes(): array
    {
        return [
            self::EVENT_ANNUAL_ENTITLEMENT_GRANTED,
            self::EVENT_CARRY_OVER_EXPIRED,
            self::EVENT_CARRY_OVER_GRANTED,
            self::EVENT_DUTY_POSTPONEMENT_RECORDED,
            self::EVENT_LEAVE_DEDUCTED,
            self::EVENT_MANUAL_ADJUSTMENT,
            self::EVENT_OPENING_BALANCE_SET,
            self::EVENT_ROLLOVER_APPLIED,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $ledger): void {
            if (! in_array($ledger->event_type, self::eventTypes(), true)) {
                throw new InvalidArgumentException(
                    "event_type ledger cuti tidak diizinkan: {$ledger->event_type}"
                );
            }
        });

        static::updating(function (): void {
            throw new LogicException('Ledger saldo cuti bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(function (): void {
            throw new LogicException('Ledger saldo cuti bersifat append-only dan tidak dapat dihapus.');
        });
    }

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
