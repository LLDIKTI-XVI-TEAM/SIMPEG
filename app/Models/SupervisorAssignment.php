<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $employee_id
 * @property string $supervisor_id
 * @property Carbon $tanggal_mulai
 * @property Carbon|null $tanggal_berakhir
 * @property-read Employee|null $supervisor
 */
class SupervisorAssignment extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'supervisor_id',
        'tanggal_mulai',
        'tanggal_berakhir',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_berakhir' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }
}
