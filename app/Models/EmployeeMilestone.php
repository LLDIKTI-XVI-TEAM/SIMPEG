<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model untuk menyimpan milestone kepegawaian yang sudah dikalkulasi.
 * Tujuan: Optimisasi EWS scheduler agar tidak perlu kalkulasi ulang setiap hari.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $type (kenaikan_pangkat, kgb, pensiun, satyalancana, pppk_contract_end)
 * @property Carbon $milestone_date
 * @property Carbon $calculated_at
 * @property array|null $metadata
 * @property bool $is_active
 * @property-read Employee $employee
 */
class EmployeeMilestone extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'type',
        'milestone_date',
        'calculated_at',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'milestone_date' => 'date',
        'calculated_at' => 'date',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Konstanta untuk tipe milestone.
     */
    public const TYPE_KENAIKAN_PANGKAT = 'kenaikan_pangkat';

    public const TYPE_KGB = 'kgb';

    public const TYPE_PENSIUN = 'pensiun';

    public const TYPE_SATYALANCANA = 'satyalancana';

    public const TYPE_PPPK_CONTRACT_END = 'pppk_contract_end';
}
