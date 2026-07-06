<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Langkah approver pada konfigurasi rantai approval cuti pegawai.
 *
 * @property int $step_order
 * @property string $step_type
 * @property string $role_label
 * @property string|null $approver_role_key
 * @property bool $is_final
 * @property-read LeaveApprovalChain|null $chain
 * @property-read Employee|null $approver
 */
class LeaveApprovalChainStep extends Model
{
    use HasUuid;

    protected $fillable = [
        'leave_approval_chain_id',
        'step_order',
        'step_type',
        'role_label',
        'approver_role_key',
        'approver_employee_id',
        'is_final',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'is_final' => 'boolean',
        ];
    }

    /** @return BelongsTo<LeaveApprovalChain, $this> */
    public function chain(): BelongsTo
    {
        return $this->belongsTo(LeaveApprovalChain::class, 'leave_approval_chain_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
