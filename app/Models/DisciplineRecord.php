<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplineRecord extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'jenis_hukuman',
        'deskripsi',
        'tanggal_mulai',
        'tanggal_berakhir',
        'no_sk',
        'tanggal_sk',
        'file_sk',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_berakhir' => 'date',
            'tanggal_sk' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
