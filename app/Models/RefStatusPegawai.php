<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $is_default
 * @property bool $is_active
 */
class RefStatusPegawai extends Model
{
    use HasUuid;

    /** @var list<string> */
    public const ACTIVE_GROUPS = ['Aktif', 'Aktif/khusus'];

    protected $table = 'ref_status_pegawai';

    protected $fillable = ['kode', 'nama', 'kelompok', 'keterangan', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Kelompok referensi adalah sumber tunggal status yang berhak menjalankan proses SIMPEG.
     * Nama atau kode status dapat berubah, tetapi kontrak kelompok aktif ini tetap dipakai lintas domain.
     *
     * @return list<string>
     */
    public static function activeGroups(): array
    {
        return self::ACTIVE_GROUPS;
    }

    /** Memeriksa kelompok status dengan fail-closed untuk referensi kosong atau tidak dikenal. */
    public static function isActiveGroup(mixed $kelompok): bool
    {
        return is_string($kelompok) && in_array(trim($kelompok), self::ACTIVE_GROUPS, true);
    }
}
