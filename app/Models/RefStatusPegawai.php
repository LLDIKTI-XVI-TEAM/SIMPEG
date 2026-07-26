<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $is_default
 * @property bool $is_active
 */
class RefStatusPegawai extends Model
{
    use HasUuid;

    protected $table = 'ref_status_pegawai';

    protected $fillable = ['kode', 'nama', 'kelompok', 'keterangan', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
