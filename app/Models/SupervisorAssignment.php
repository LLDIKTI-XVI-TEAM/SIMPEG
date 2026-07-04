<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'kepala_bagian_id',
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
        return $this->belongsTo(Employee::class, 'kepala_bagian_id');
    }

    public function kepalaBagian(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'kepala_bagian_id');
    }

    protected function supervisorId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $attributes['kepala_bagian_id'] ?? $value,
            set: fn ($value) => [
                'supervisor_id' => $value,
                'kepala_bagian_id' => $value,
            ],
        );
    }

    protected function kepalaBagianId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, array $attributes) => $value ?? ($attributes['supervisor_id'] ?? null),
            set: fn ($value) => [
                'kepala_bagian_id' => $value,
                'supervisor_id' => $value,
            ],
        );
    }
}
