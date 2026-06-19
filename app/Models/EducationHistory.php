<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EducationHistory extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'jenjang_id',
        'nama_institusi',
        'jurusan',
        'tahun_lulus',
        'no_ijazah',
        'file_ijazah',
    ];

    protected function casts(): array
    {
        return [
            'tahun_lulus' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(RefJenjangPendidikan::class, 'jenjang_id');
    }
}
