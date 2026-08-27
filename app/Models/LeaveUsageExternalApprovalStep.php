<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $step_order
 * @property string $step_type
 * @property string $approver_source
 * @property string|null $approver_employee_id
 * @property string $approver_name_snapshot
 * @property string|null $approver_nip_snapshot
 * @property string|null $approver_position_snapshot
 * @property string $approver_institution_snapshot
 * @property Carbon $acted_on
 * @property string $result_code
 * @property string|null $decision_note
 */
final class LeaveUsageExternalApprovalStep extends Model
{
    use HasUuid;

    public const TYPE_VERIFIER = 'verifier';

    public const TYPE_KEPALA_BAGIAN = 'kepala_bagian';

    public const TYPE_PYBMC = 'pybmc';

    public const SOURCE_SIMPEG_EMPLOYEE = 'simpeg_employee';

    public const SOURCE_EXTERNAL_OFFICIAL = 'external_official';

    public const RESULT_VERIFIED = 'verified';

    public const RESULT_APPROVED = 'approved';

    public const RESULT_FINAL_APPROVED = 'final_approved';

    protected $fillable = [
        'leave_usage_record_id',
        'step_order',
        'step_type',
        'approver_source',
        'approver_employee_id',
        'approver_name_snapshot',
        'approver_nip_snapshot',
        'approver_position_snapshot',
        'approver_institution_snapshot',
        'acted_on',
        'result_code',
        'decision_note',
    ];

    protected static function booted(): void
    {
        self::deleting(function (): void {
            throw new LogicException('Snapshot persetujuan eksternal bersifat append-only dan tidak dapat dihapus.');
        });
    }

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'acted_on' => 'date',
        ];
    }

    /** @return BelongsTo<LeaveUsageRecord, $this> */
    public function leaveUsageRecord(): BelongsTo
    {
        return $this->belongsTo(LeaveUsageRecord::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function approverEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
