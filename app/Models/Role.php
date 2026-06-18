<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasUuid;

    protected $fillable = [
        'name',
        'guard_name',
        'description',
    ];
}
