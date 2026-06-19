<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefUnitKerja extends Model
{
    use HasUuid;

    protected $table = 'ref_unit_kerja';

    protected $fillable = ['nama', 'keterangan'];
}
