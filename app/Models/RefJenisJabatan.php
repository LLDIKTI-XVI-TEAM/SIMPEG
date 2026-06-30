<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $maks_usia_pensiun
 */
class RefJenisJabatan extends Model
{
    use HasUuid;

    protected $table = 'ref_jenis_jabatan';

    protected $fillable = ['nama', 'maks_usia_pensiun', 'catatan'];

    protected function casts(): array
    {
        return [
            'maks_usia_pensiun' => 'integer',
        ];
    }
}
