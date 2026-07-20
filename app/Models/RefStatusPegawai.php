<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefStatusPegawai extends Model
{
    use HasUuid;

    protected $table = 'ref_status_pegawai';

    protected $fillable = ['kode', 'nama', 'kelompok', 'keterangan', 'is_default'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
