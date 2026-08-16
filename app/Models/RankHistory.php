<?php

namespace App\Models;

use App\Contracts\HasSkDocument;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string|null $no_sk
 * @property string|null $file_sk
 * @property Carbon $tmt_pangkat
 * @property Carbon|null $tanggal_sk
 */
class RankHistory extends Model implements HasSkDocument
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'golongan_id',
        'tmt_pangkat',
        'no_sk',
        'tanggal_sk',
        'file_sk',
        'is_latest',
    ];

    protected function casts(): array
    {
        return [
            'tmt_pangkat' => 'date',
            'tanggal_sk' => 'date',
            'is_latest' => 'boolean',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<RefGolongan, $this> */
    public function golongan(): BelongsTo
    {
        return $this->belongsTo(RefGolongan::class, 'golongan_id');
    }
}
