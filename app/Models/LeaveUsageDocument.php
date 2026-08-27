<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LeaveUsageDocument extends Model
{
    use HasUuid;

    public const STORAGE_DISK = 'local';

    public const PATH_PREFIX = 'cuti/pemakaian';

    protected $fillable = [
        'leave_usage_record_id',
        'leave_usage_reconciliation_set_id',
        'original_name',
        'stored_name',
        'path',
        'disk',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected static function booted(): void
    {
        // Koreksi dokumen selalu membuat record baru agar metadata dan berkas lama tetap dapat diaudit.
        static::updating(function (): void {
            throw new LogicException('Metadata dokumen pemakaian cuti bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(function (): void {
            throw new LogicException('Metadata dokumen pemakaian cuti bersifat append-only dan tidak dapat dihapus.');
        });
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /** @return BelongsTo<LeaveUsageRecord, $this> */
    public function usageRecord(): BelongsTo
    {
        return $this->belongsTo(LeaveUsageRecord::class, 'leave_usage_record_id');
    }

    /** @return BelongsTo<LeaveUsageReconciliationSet, $this> */
    public function reconciliationSet(): BelongsTo
    {
        return $this->belongsTo(LeaveUsageReconciliationSet::class, 'leave_usage_reconciliation_set_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
