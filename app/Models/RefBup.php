<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $is_active
 */
class RefBup extends Model
{
    use HasUuid;

    protected $table = 'ref_bup';

    protected $fillable = ['jenis_jabatan', 'bup_tahun', 'is_active'];

    protected function casts(): array
    {
        return [
            'bup_tahun' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
