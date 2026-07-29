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
 * @property int $sisa_n2
 * @property int $sisa_n1
 * @property int $sisa_tahun_berjalan
 * @property int $terpakai_tahun_berjalan
 * @property int $hangus
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
        'sisa_n2',
        'sisa_n1',
        'sisa_tahun_berjalan',
        'terpakai_tahun_berjalan',
        'hangus',
    ];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'jatah_awal' => 'integer',
            'carry_over' => 'integer',
            'terpakai' => 'integer',
            'sisa' => 'integer',
            'sisa_n2' => 'integer',
            'sisa_n1' => 'integer',
            'sisa_tahun_berjalan' => 'integer',
            'terpakai_tahun_berjalan' => 'integer',
            'hangus' => 'integer',
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

    /** @return HasMany<LeaveBalanceReservationEvent, $this> */
    public function reservationEvents(): HasMany
    {
        return $this->hasMany(LeaveBalanceReservationEvent::class);
    }
}
