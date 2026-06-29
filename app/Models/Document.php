<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $jenis_dokumen
 * @property string $nama_dokumen
 * @property string|null $nomor_dokumen
 * @property string $file_path
 * @property string|null $keterangan
 * @property Carbon|null $tanggal_dokumen
 * @property-read Employee|null $employee
 */
class Document extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'jenis_dokumen',
        'nama_dokumen',
        'nomor_dokumen',
        'tanggal_dokumen',
        'file_path',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_dokumen' => 'date',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
