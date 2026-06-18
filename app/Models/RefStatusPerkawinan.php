<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefStatusPerkawinan extends Model
{
    use HasUuid;

    protected $table = 'ref_status_perkawinan';

    protected $fillable = ['nama'];
}
