<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Konfigurasi PYBMC global sebagai fallback final approver saat chain pegawai belum punya final khusus.
 *
 * @property string $approver_employee_id
 * @property Carbon $effective_from
 * @property string|null $created_by
 * @property string|null $change_reason
 * @property-read Employee|null $approver
 */
class LeavePybmcGlobalConfig extends Model
{
    use HasUuid;

    protected $table = 'leave_pybmc_global_config';

    protected $fillable = [
        'approver_employee_id',
        'effective_from',
        'created_by',
        'change_reason',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
