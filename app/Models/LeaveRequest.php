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
 * @property int $revision_version
 * @property int $jumlah_hari_kerja
 * @property string $alasan
 * @property string|null $alamat_selama_cuti
 * @property string|null $nomor_telepon
 * @property Carbon $tanggal_mulai
 * @property Carbon $tanggal_selesai
 * @property Carbon|null $created_at
 * @property Carbon|null $administratively_postponed_at
 * @property string|null $administratively_postponed_by
 * @property string|null $administrative_postponement_reason
 * @property-read User|null $administrativelyPostponedBy
 * @property-read Employee|null $employee
 * @property-read RefJenisCuti|null $jenisCuti
 * @property-read LeaveRequestCase|null $leaveRequestCase
 * @property-read LeaveProof|null $proof
 * @property LeaveRequestStep|null $activeStep
 */
class LeaveRequest extends Model
{
    use HasUuid;

    public const ATTACHMENT_STORAGE_DISK = 'local';

    public const ATTACHMENT_PATH_PREFIX = 'cuti/lampiran';

    public const STATUS_DUTY_POSTPONED = 'ditangguhkan_tugas_dinas';

    public const STATUS_RETURNED_FOR_ROLLOVER = 'dikembalikan_karena_rollover';

    public const STATUS_CANCELLATION_PENDING = 'menunggu_pembatalan';

    public const STATUS_CANCELLED = 'dibatalkan';

    public const STATUS_ADMINISTRATIVELY_POSTPONED = 'ditangguhkan_administratif';

    // Default model menjaga payload notifikasi pertama memiliki versi sebelum reload dari database.
    protected $attributes = [
        'revision_version' => 1,
    ];

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

    // Keputusan dan identitas aktor hanya dibuka melalui payload detail yang sudah diotorisasi.
    protected $hidden = [
        'administratively_postponed_at',
        'administratively_postponed_by',
        'administrative_postponement_reason',
        'administrativelyPostponedBy',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'jumlah_hari_kerja' => 'integer',
            'rollover_source_year' => 'integer',
            'rollover_target_year' => 'integer',
            'revision_version' => 'integer',
            'administratively_postponed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function administrativelyPostponedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'administratively_postponed_by');
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

    /** @return HasMany<LeaveCancellationRequest, $this> */
    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(LeaveCancellationRequest::class);
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

    /** @return HasOne<LeaveUsageRecord, $this> */
    public function usageRecord(): HasOne
    {
        return $this->hasOne(LeaveUsageRecord::class, 'leave_request_id');
    }
}
