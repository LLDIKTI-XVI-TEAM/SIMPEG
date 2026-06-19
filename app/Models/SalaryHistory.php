<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryHistory extends Model
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
