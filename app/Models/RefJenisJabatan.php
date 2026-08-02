<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $maks_usia_pensiun
 * @property bool $is_active
 */
class RefJenisJabatan extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'ref_jenis_jabatan';

    protected $fillable = ['nama', 'maks_usia_pensiun', 'catatan', 'is_active'];

    protected function casts(): array
    {
        return [
            'maks_usia_pensiun' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
