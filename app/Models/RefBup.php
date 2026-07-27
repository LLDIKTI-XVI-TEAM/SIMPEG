<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @deprecated Tabel ini tidak dipakai perhitungan BUP mana pun. Sumber BUP resmi adalah
 * RefJabatan::default_bup dengan fallback RefJenisJabatan::maks_usia_pensiun. Tabel, seeder,
 * dan migrasi sengaja dipertahankan pada Fase 1; penghapusannya dijadwalkan ke Fase 2.
 *
 * @property bool $is_active
 */
class RefBup extends Model
{
    use HasUuid;

    protected $table = 'ref_bup';

    protected $fillable = ['jenis_jabatan', 'bup_tahun', 'is_active'];

    protected function casts(): array
    {
        return [
            'bup_tahun' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
