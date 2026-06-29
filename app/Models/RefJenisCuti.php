<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $nama
 * @property bool $khusus_pns
 */
class RefJenisCuti extends Model
{
    use HasUuid;

    protected $table = 'ref_jenis_cuti';

    protected $fillable = ['nama', 'khusus_pns'];

    protected function casts(): array
    {
        return [
            'khusus_pns' => 'boolean',
        ];
    }
}
