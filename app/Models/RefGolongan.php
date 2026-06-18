<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefGolongan extends Model
{
    use HasUuid;

    protected $table = 'ref_golongan';

    protected $fillable = ['kode', 'nama', 'urutan'];
}
