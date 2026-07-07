<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $employee_id
 * @property string $status
 * @property int $jumlah_hari_kerja
 * @property string $alasan
 * @property int $current_stage
 * @property Carbon $tanggal_mulai
 * @property Carbon $tanggal_selesai
 * @property Carbon|null $created_at
 * @property-read Employee|null $employee
 * @property-read RefJenisCuti|null $jenisCuti
 * @property-read LeaveProof|null $proof
 */
class LeaveRequest extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'jenis_cuti_id',
        'tanggal_mulai',
        'tanggal_selesai',
        'jumlah_hari_kerja',
        'alasan',
        'lampiran_path',
        'status',
        'current_stage',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'jumlah_hari_kerja' => 'integer',
            'current_stage' => 'integer',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<RefJenisCuti, $this> */
    public function jenisCuti(): BelongsTo
    {
        return $this->belongsTo(RefJenisCuti::class, 'jenis_cuti_id');
    }

    /** @return HasMany<LeaveApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(LeaveApproval::class);
    }

    /** @return HasMany<LeaveRequestStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(LeaveRequestStep::class);
    }

    /** @return HasOne<LeaveProof, $this> */
    public function proof(): HasOne
    {
        return $this->hasOne(LeaveProof::class);
    }
}
