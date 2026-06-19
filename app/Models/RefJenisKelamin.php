<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefJenisKelamin extends Model
{
    use HasUuid;

    protected $table = 'ref_jenis_kelamin';

    protected $fillable = ['kode', 'nama'];
}
