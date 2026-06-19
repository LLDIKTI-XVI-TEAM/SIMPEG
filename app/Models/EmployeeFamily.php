<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeFamily extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'nama_anggota',
        'hubungan',
        'nik',
        'tempat_lahir',
        'tanggal_lahir',
        'jenis_kelamin',
        'status_tunjangan',
        'pekerjaan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'status_tunjangan' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
