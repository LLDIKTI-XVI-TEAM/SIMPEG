<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * Buku kejadian append-only untuk alokasi sementara saldo Cuti Tahunan.
 *
 * Nilai positif menambah alokasi aktif, sedangkan nilai negatif mengurangi
 * alokasi saat pengajuan diperbaiki, dikonversi menjadi pemakaian final,
 * atau tidak disetujui. Tabel ini sengaja terpisah dari ledger saldo final.
 *
 * @property string $employee_id
 * @property string $leave_request_id
 * @property string|null $leave_balance_id
 * @property int $tahun
 * @property string $event_type
 * @property int $amount
 * @property string|null $dedup_key
 * @property array<string, mixed>|null $metadata
 */
class LeaveBalanceReservationEvent extends Model
{
    use HasUuid;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    public const EVENT_RESERVED = 'reserved';

    public const EVENT_ADJUSTED = 'adjusted';

    public const EVENT_CONVERTED = 'converted';

    public const EVENT_RELEASED = 'released';

    /** @var list<string> */
    private const ACTIVE_REQUEST_STATUSES = [
        'menunggu_approval',
        'ditangguhkan',
        'perlu_perubahan',
    ];

    protected $table = 'leave_balance_reservation_events';

    protected $fillable = [
        'employee_id',
        'leave_request_id',
        'leave_balance_id',
        'tahun',
        'event_type',
        'amount',
        'reason',
        'dedup_key',
        'metadata',
        'created_by',
        'occurred_at',
    ];

    /** @return list<string> */
    public static function eventTypes(): array
    {
        return [
            self::EVENT_RESERVED,
            self::EVENT_ADJUSTED,
            self::EVENT_CONVERTED,
            self::EVENT_RELEASED,
        ];
    }

    /** @return list<string> */
    public static function activeRequestStatuses(): array
    {
        return self::ACTIVE_REQUEST_STATUSES;
    }

    protected static function booted(): void
    {
        static::saving(function (self $event): void {
            if (! in_array($event->event_type, self::eventTypes(), true)) {
                throw new InvalidArgumentException(
                    "event_type reservasi saldo cuti tidak diizinkan: {$event->event_type}"
                );
            }
        });

        static::updating(function (): void {
            throw new LogicException('Event reservasi saldo cuti bersifat append-only dan tidak dapat diubah.');
        });

        static::deleting(function (): void {
            throw new LogicException('Event reservasi saldo cuti bersifat append-only dan tidak dapat dihapus.');
        });
    }

    /** @param Builder<self> $query */
    public function scopeForActiveRequests(Builder $query): void
    {
        $query->whereHas('leaveRequest', function (Builder $leaveRequests): void {
            // Kode jenis cuti adalah otoritas domain. Filter ini juga melindungi pembacaan
            // event legacy yang sempat dibuat ketika flag referensi lama masih kotor.
            $leaveRequests
                ->whereIn('status', self::activeRequestStatuses())
                ->whereHas('jenisCuti', function (Builder $leaveTypes): void {
                    $leaveTypes->where('code', RefJenisCuti::CODE_TAHUNAN);
                });
        });
    }

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'amount' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<LeaveRequest, $this> */
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    /** @return BelongsTo<LeaveBalance, $this> */
    public function leaveBalance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class);
    }
}
