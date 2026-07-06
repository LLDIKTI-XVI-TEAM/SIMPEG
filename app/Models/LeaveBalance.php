<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $tahun
 * @property int $jatah_awal
 * @property int $carry_over
 * @property int $terpakai
 * @property int $sisa
 */
class LeaveBalance extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'tahun',
        'jatah_awal',
        'carry_over',
        'terpakai',
        'sisa',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'jatah_awal' => 'integer',
            'carry_over' => 'integer',
            'terpakai' => 'integer',
            'sisa' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<LeaveBalanceLedger, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LeaveBalanceLedger::class);
    }
}
