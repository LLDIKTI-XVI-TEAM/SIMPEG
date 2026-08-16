<?php

namespace App\Models;

use App\Contracts\HasSkDocument;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $no_sk
 * @property string|null $file_sk
 * @property Carbon $tmt_kgb
 * @property Carbon|null $tanggal_sk
 */
class SalaryHistory extends Model implements HasSkDocument
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'tmt_kgb',
        'gaji_pokok',
        'no_sk',
        'tanggal_sk',
        'file_sk',
        'is_latest',
    ];

    protected function casts(): array
    {
        return [
            'tmt_kgb' => 'date',
            'tanggal_sk' => 'date',
            'gaji_pokok' => 'decimal:2',
            'is_latest' => 'boolean',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
