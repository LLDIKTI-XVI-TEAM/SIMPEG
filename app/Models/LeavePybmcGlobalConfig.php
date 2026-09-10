<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Konfigurasi PYBMC global sebagai fallback final approver saat chain pegawai belum punya final khusus.
 *
 * @property string $approver_employee_id
 * @property Carbon $effective_from
 * @property string|null $created_by
 * @property string|null $change_reason
 * @property int $revision
 * @property-read Employee|null $approver
 */
class LeavePybmcGlobalConfig extends Model
{
    use HasUuid;

    protected $table = 'leave_pybmc_global_config';

    protected $fillable = [
        'approver_employee_id',
        'effective_from',
        'created_by',
        'change_reason',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'revision' => 'integer',
        ];
    }

    /**
     * Urutan writer yang terserialisasi mengungguli timestamp detik dan UUID acak.
     * Histori revisi nol tetap memakai urutan waktu yang telah diperiksa saat migrasi.
     *
     * @param  Builder<LeavePybmcGlobalConfig>  $query
     * @return Builder<LeavePybmcGlobalConfig>
     */
    public function scopeLatestRevision(Builder $query): Builder
    {
        return $query->orderByDesc('revision')->orderByDesc('effective_from')->orderByDesc('created_at');
    }

    /** @return BelongsTo<Employee, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_employee_id');
    }
}
