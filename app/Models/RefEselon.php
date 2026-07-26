<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $is_active
 */
class RefEselon extends Model
{
    use HasUuid;

    protected $table = 'ref_eselon';

    protected $fillable = ['kode', 'nama', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
