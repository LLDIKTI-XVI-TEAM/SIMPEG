<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Rangkaian pengajuan untuk satu kebutuhan Cuti Melahirkan atau CLTN.
 *
 * Rangkaian menjadi penghubung eksplisit saat periode perlu dipecah per tahun
 * kalender. Identitas rangkaian bersifat append-only: request yang terhubung
 * membentuk bukti audit, bukan kesimpulan dari teks alasan bebas.
 *
 * @property string $id
 * @property string $employee_id
 * @property string $jenis_cuti_id
 * @property string|null $created_by
 * @property-read Employee|null $employee
 * @property-read RefJenisCuti|null $jenisCuti
 */
class LeaveRequestCase extends Model
{
    use HasUuid;

    protected $fillable = [
        'employee_id',
        'jenis_cuti_id',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Rangkaian pengajuan cuti bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(function (): never {
            throw new LogicException('Rangkaian pengajuan cuti bersifat append-only dan tidak dapat dihapus.');
        });
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<LeaveRequest, $this> */
    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class, 'leave_request_case_id');
    }

    /** @return HasMany<LeaveUsageRecord, $this> */
    public function leaveUsageRecords(): HasMany
    {
        return $this->hasMany(LeaveUsageRecord::class, 'leave_request_case_id');
    }
}
