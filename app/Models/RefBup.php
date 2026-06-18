<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefBup extends Model
{
    use HasUuid;

    protected $table = 'ref_bup';

    protected $fillable = ['jenis_jabatan', 'bup_tahun'];

    protected function casts(): array
    {
        return [
            'bup_tahun' => 'integer',
        ];
    }
}
