<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Menyimpan satu pasangan jenis pegawai dan kategori SK pada matriks wajib.
 *
 * @property string $id
 * @property string $jenis_pegawai_id
 * @property string $sk_key
 * @property bool $is_wajib
 * @property-read RefJenisPegawai|null $jenisPegawai
 */
class SkRequirement extends Model
{
    use HasUuid;

    protected $fillable = [
        'jenis_pegawai_id',
        'sk_key',
        'is_wajib',
    ];

    protected function casts(): array
    {
        return [
            'is_wajib' => 'boolean',
        ];
    }

    /** @return BelongsTo<RefJenisPegawai, $this> */
    public function jenisPegawai(): BelongsTo
    {
        return $this->belongsTo(RefJenisPegawai::class, 'jenis_pegawai_id');
    }
}
