<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
