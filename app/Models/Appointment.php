<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string|null $jenis_pengangkatan
 * @property string|null $no_sk
 * @property Carbon|null $tmt_pengangkatan
 * @property Carbon|null $tanggal_sk
 */
class Appointment extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'jenis_pengangkatan',
        'tmt_pengangkatan',
        'no_sk',
        'tanggal_sk',
        'file_sk',
    ];

    protected function casts(): array
    {
        return [
            'tmt_pengangkatan' => 'date',
            'tanggal_sk' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
