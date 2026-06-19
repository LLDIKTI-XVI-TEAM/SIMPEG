<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

class RefJenisPegawai extends Model
{
    use HasUuid;

    protected $table = 'ref_jenis_pegawai';

    protected $fillable = ['nama'];
}
