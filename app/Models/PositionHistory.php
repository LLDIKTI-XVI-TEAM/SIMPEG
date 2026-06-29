<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $nama_jabatan
 * @property string|null $no_sk
 * @property string|null $file_sk
 * @property Carbon $tmt_jabatan
 * @property Carbon|null $tanggal_sk
 * @property-read RefUnitKerja|null $unitKerja
 */
class PositionHistory extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'nama_jabatan',
        'jenis_jabatan_id',
        'eselon_id',
        'unit_kerja_id',
        'tmt_jabatan',
        'no_sk',
        'tanggal_sk',
        'file_sk',
        'is_latest',
    ];

    protected function casts(): array
    {
        return [
            'tmt_jabatan' => 'date',
            'tanggal_sk' => 'date',
            'is_latest' => 'boolean',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<RefJenisJabatan, $this> */
    public function jenisJabatan(): BelongsTo
    {
        return $this->belongsTo(RefJenisJabatan::class, 'jenis_jabatan_id');
    }

    /** @return BelongsTo<RefEselon, $this> */
    public function eselon(): BelongsTo
    {
        return $this->belongsTo(RefEselon::class, 'eselon_id');
    }

    /** @return BelongsTo<RefUnitKerja, $this> */
    public function unitKerja(): BelongsTo
    {
        return $this->belongsTo(RefUnitKerja::class, 'unit_kerja_id');
    }
}
