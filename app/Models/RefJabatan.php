<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefJabatan extends Model
{
    use HasUuid;

    protected $table = 'ref_jabatan';

    protected $fillable = [
        'nama',
        'jenis_jabatan_id',
        'eselon_id',
        'keterangan',
    ];

    public function jenisJabatan(): BelongsTo
    {
        return $this->belongsTo(RefJenisJabatan::class, 'jenis_jabatan_id');
    }

    public function eselon(): BelongsTo
    {
        return $this->belongsTo(RefEselon::class, 'eselon_id');
    }
}
