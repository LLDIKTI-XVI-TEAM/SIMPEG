<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Snapshot konfigurasi rantai approval per pegawai sebelum pengajuan cuti dibuat.
 *
 * @property string $employee_id
 * @property string $name
 * @property bool $is_active
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 * @property string|null $created_by
 * @property string|null $updated_by
 * @property string|null $change_reason
 * @property-read Employee|null $employee
 */
class LeaveApprovalChain extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'name',
        'is_active',
        'effective_from',
        'effective_until',
        'created_by',
        'updated_by',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<LeaveApprovalChainStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(LeaveApprovalChainStep::class);
    }
}
