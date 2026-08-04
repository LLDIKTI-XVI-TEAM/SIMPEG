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
 * @property string|null $leave_request_case_id
 * @property string $status
 * @property int $jumlah_hari_kerja
 * @property string $alasan
 * @property string|null $alamat_selama_cuti
 * @property string|null $nomor_telepon
 * @property Carbon $tanggal_mulai
 * @property Carbon $tanggal_selesai
 * @property Carbon|null $created_at
 * @property-read Employee|null $employee
 * @property-read RefJenisCuti|null $jenisCuti
 * @property-read LeaveRequestCase|null $leaveRequestCase
 * @property-read LeaveProof|null $proof
 * @property LeaveRequestStep|null $activeStep
 */
class LeaveRequest extends Model
{
    use HasUuid;

    public const STATUS_DUTY_POSTPONED = 'ditangguhkan_tugas_dinas';

    public const STATUS_RETURNED_FOR_ROLLOVER = 'dikembalikan_karena_rollover';

    protected $fillable = [
        'employee_id',
        'jenis_cuti_id',
        'leave_request_case_id',
        'tanggal_mulai',
        'tanggal_selesai',
        'jumlah_hari_kerja',
        'alasan',
        'alamat_selama_cuti',
        'nomor_telepon',
        'lampiran_path',
        'status',
        'rollover_source_year',
        'rollover_target_year',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'jumlah_hari_kerja' => 'integer',
            'rollover_source_year' => 'integer',
            'rollover_target_year' => 'integer',
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

    /** @return BelongsTo<LeaveRequestCase, $this> */
    public function leaveRequestCase(): BelongsTo
    {
        return $this->belongsTo(LeaveRequestCase::class, 'leave_request_case_id');
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

    /** @return HasMany<LeaveBalanceReservationEvent, $this> */
    public function balanceReservationEvents(): HasMany
    {
        return $this->hasMany(LeaveBalanceReservationEvent::class);
    }
}
