<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefAgama extends Model
{
    use HasUuid;

    protected $table = 'ref_agama';

    protected $fillable = ['nama'];
}
