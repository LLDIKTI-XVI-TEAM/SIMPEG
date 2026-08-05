<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Snapshot langkah approval pada pengajuan cuti agar perubahan konfigurasi tidak mengubah request lama.
 *
 * @property int $step_order
 * @property string $step_type
 * @property string $role_label
 * @property string $status
 * @property bool $is_final
 * @property string|null $skipped_reason
 * @property Carbon|null $acted_at
 * @property-read LeaveRequest|null $leaveRequest
 * @property-read Employee|null $approver
 */
class LeaveRequestStep extends Model
{
    use HasUuid;

    public const SKIPPED_DUTY_POSTPONEMENT_TERMINAL = 'duty_postponement_terminal';

    public const STATUS_DUTY_POSTPONED = 'ditangguhkan_tugas_dinas';

    protected $fillable = [
        'leave_request_id',
        'step_order',
        'step_type',
        'role_label',
        'approver_employee_id',
        'status',
        'is_final',
        'skipped_reason',
        'acted_at',
        'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'is_final' => 'boolean',
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
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
