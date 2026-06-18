<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefEselon extends Model
{
    use HasUuid;

    protected $table = 'ref_eselon';

    protected $fillable = ['kode', 'nama'];
}
