<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Bukti publik pengajuan cuti yang sudah disetujui dan diverifikasi melalui token QR.
 *
 * @property string $token
 * @property string|null $document_path
 * @property string|null $generated_by
 * @property Carbon|null $generated_at
 * @property-read LeaveRequest|null $leaveRequest
 * @property-read User|null $generatedBy
 */
class LeaveProof extends Model
{
    use HasUuid;

    protected $fillable = [
        'leave_request_id',
        'token',
        'document_path',
        'generated_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
