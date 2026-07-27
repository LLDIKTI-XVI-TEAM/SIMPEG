<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $is_active
 */
class RefJenjangPendidikan extends Model
{
    use HasUuid;

    protected $table = 'ref_jenjang_pendidikan';

    protected $fillable = ['nama', 'urutan', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
