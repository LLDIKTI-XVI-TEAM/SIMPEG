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
 * @property Carbon $effective_date
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 */
class LeaveUsageRecord extends Model
{
    use HasUuid;

    public const SOURCE_APPROVED_REQUEST = 'approved_request';

    public const SOURCE_MANUAL_EXTERNAL = 'manual_external';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'source_type',
        'leave_request_id',
        'leave_request_case_id',
        'usage_year',
        'effective_date',
        'start_date',
        'end_date',
        'workdays',
        'administrative_note',
        'approval_document_number',
        'record_status',
        'replaces_id',
        'correction_reason',
        'recorded_by',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new LogicException('Fakta pemakaian cuti bersifat historis dan tidak dapat dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'usage_year' => 'integer',
            'effective_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
            'workdays' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<RefJenisCuti, $this> */
    public function jenisCuti(): BelongsTo
    {
        return $this->belongsTo(RefJenisCuti::class, 'leave_type_id');
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /** @return BelongsTo<LeaveRequestCase, $this> */
    public function leaveRequestCase(): BelongsTo
    {
        return $this->belongsTo(LeaveRequestCase::class);
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

    /** @return HasMany<LeaveUsageDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(LeaveUsageDocument::class, 'leave_usage_record_id');
    }

    /**
     * Snapshot historis selalu dibaca berdasarkan urutan tersimpan, bukan konfigurasi approval terkini.
     *
     * @return HasMany<LeaveUsageExternalApprovalStep, $this>
     */
    public function externalApprovalSteps(): HasMany
    {
        return $this->hasMany(LeaveUsageExternalApprovalStep::class, 'leave_usage_record_id')
            ->orderBy('step_order');
    }
}
