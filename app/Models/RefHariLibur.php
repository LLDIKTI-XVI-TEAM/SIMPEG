<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefHariLibur extends Model
{
    use HasUuid;

    protected $table = 'ref_hari_libur';

    protected $fillable = ['tanggal', 'nama', 'tahun', 'is_cuti_bersama'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'is_cuti_bersama' => 'boolean',
        ];
    }
}
