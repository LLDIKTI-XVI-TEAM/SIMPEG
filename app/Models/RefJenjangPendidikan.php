<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefJenjangPendidikan extends Model
{
    use HasUuid;

    protected $table = 'ref_jenjang_pendidikan';

    protected $fillable = ['nama', 'urutan'];
}
