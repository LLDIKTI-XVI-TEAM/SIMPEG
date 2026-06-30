<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Carbon|null $tanggal
 * @property string $nama
 * @property int $tahun
 * @property bool $is_cuti_bersama
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RefHariLibur extends Model
{
    use HasUuid;

    protected $table = 'ref_hari_libur';

    protected $fillable = ['tanggal', 'nama', 'tahun', 'is_cuti_bersama'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'is_cuti_bersama' => 'boolean',
        ];
    }

    public function tipe(): string
    {
        return $this->is_cuti_bersama ? 'cuti_bersama' : 'libur_nasional';
    }

    public function labelTipe(): string
    {
        return $this->is_cuti_bersama ? 'Cuti Bersama' : 'Libur Nasional';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'tanggal' => $this->tanggal?->format('Y-m-d'),
            'nama' => $this->nama,
            'tahun' => $this->tahun,
            'is_cuti_bersama' => (bool) $this->is_cuti_bersama,
            'tipe' => $this->tipe(),
            'label_tipe' => $this->labelTipe(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
