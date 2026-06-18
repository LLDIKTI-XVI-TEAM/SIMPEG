<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PositionHistory extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'nama_jabatan',
        'jenis_jabatan_id',
        'eselon_id',
        'unit_kerja_id',
        'tmt_jabatan',
        'no_sk',
        'tanggal_sk',
        'file_sk',
        'is_latest',
    ];

    protected function casts(): array
    {
        return [
            'tmt_jabatan' => 'date',
            'tanggal_sk' => 'date',
            'is_latest' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function jenisJabatan(): BelongsTo
    {
        return $this->belongsTo(RefJenisJabatan::class, 'jenis_jabatan_id');
    }

    public function eselon(): BelongsTo
    {
        return $this->belongsTo(RefEselon::class, 'eselon_id');
    }

    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(RefUnitKerja::class, 'unit_kerja_id');
    }
}
