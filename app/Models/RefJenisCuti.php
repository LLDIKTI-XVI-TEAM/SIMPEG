<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $nama
 * @property string|null $code
 * @property bool $mengurangi_saldo_tahunan
 * @property bool $khusus_pns
 */
class RefJenisCuti extends Model
{
    use HasUuid;

    public const CODE_TAHUNAN = 'tahunan';

    protected $table = 'ref_jenis_cuti';

    protected $fillable = ['nama', 'code', 'mengurangi_saldo_tahunan', 'khusus_pns'];

    protected function casts(): array
    {
        return [
            'mengurangi_saldo_tahunan' => 'boolean',
            'khusus_pns' => 'boolean',
        ];
    }

    /** Kode kanonik menjadi sumber keputusan saldo; flag database hanya invariant tersimpan. */
    public function reducesAnnualBalance(): bool
    {
        return $this->code === self::CODE_TAHUNAN;
    }
}
