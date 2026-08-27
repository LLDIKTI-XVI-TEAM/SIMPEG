<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property Carbon $reconciled_at
 */
class LeaveUsageReconciliationSet extends Model
{
    use HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'employee_id',
        'balance_year',
        'reconciled_at',
        'status',
        'replaces_id',
        'administrative_note',
        'correction_reason',
        'recorded_by',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Set rekonsiliasi pemakaian cuti bersifat historis dan tidak dapat dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'balance_year' => 'integer',
            'reconciled_at' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return BelongsTo<self, $this> */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /** @return HasOne<self, $this> */
    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_id');
    }

    /** @return HasMany<LeaveUsageRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(LeaveUsageRecord::class, 'reconciliation_set_id');
    }

    /** @return HasMany<LeaveUsageReconciliationMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(LeaveUsageReconciliationMembership::class, 'reconciliation_set_id');
    }

    /** @return HasMany<LeaveUsageDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(LeaveUsageDocument::class, 'leave_usage_reconciliation_set_id');
    }
}
