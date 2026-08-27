<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LeaveUsageReconciliationMembership extends Model
{
    use HasUuid;

    protected $fillable = [
        'reconciliation_set_id',
        'annual_reconciliation_record_id',
        'itemized_usage_record_id',
        'included_workdays',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Membership rekonsiliasi pemakaian cuti bersifat immutable dan tidak dapat diubah.');
        });

        static::deleting(function (): void {
            throw new LogicException('Membership rekonsiliasi pemakaian cuti bersifat historis dan tidak dapat dihapus.');
        });
    }

    protected function casts(): array
    {
        return ['included_workdays' => 'integer'];
    }

    /** @return BelongsTo<LeaveUsageReconciliationSet, $this> */
    public function reconciliationSet(): BelongsTo
    {
        return $this->belongsTo(LeaveUsageReconciliationSet::class, 'reconciliation_set_id');
    }

    /** @return BelongsTo<LeaveUsageRecord, $this> */
    public function annualReconciliationRecord(): BelongsTo
    {
        return $this->belongsTo(LeaveUsageRecord::class, 'annual_reconciliation_record_id');
    }

    /** @return BelongsTo<LeaveUsageRecord, $this> */
    public function itemizedUsageRecord(): BelongsTo
    {
        return $this->belongsTo(LeaveUsageRecord::class, 'itemized_usage_record_id');
    }
}
