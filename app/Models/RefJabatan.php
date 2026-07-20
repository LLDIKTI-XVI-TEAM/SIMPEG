<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $nama
 * @property string|null $jenis_jabatan_id
 * @property string|null $eselon_id
 * @property int|null $default_bup
 * @property bool $is_active
 * @property string|null $keterangan
 * @property-read RefJenisJabatan|null $jenisJabatan
 * @property-read RefEselon|null $eselon
 */
class RefJabatan extends Model
{
    use HasUuid;

    protected $table = 'ref_jabatan';

    protected $fillable = [
        'nama',
        'jenis_jabatan_id',
        'eselon_id',
        'default_bup',
        'is_active',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'default_bup' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function jenisJabatan(): BelongsTo
    {
        return $this->belongsTo(RefJenisJabatan::class, 'jenis_jabatan_id');
    }

    public function eselon(): BelongsTo
    {
        return $this->belongsTo(RefEselon::class, 'eselon_id');
    }
}
