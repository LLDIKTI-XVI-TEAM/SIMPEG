<?php

namespace App\Models;

use App\Casts\MaskedAuditPayload;
use App\Exceptions\ImmutableAuditLogException;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 */
class AuditLog extends Model
{
    use HasUuid;

    /**
     * Audit logs are immutable — no updated_at needed.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'user_name',
        'event',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * Payload audit memakai cast yang menyamarkan nomor identitas pada kedua arah, sehingga
     * tidak ada permukaan baca yang perlu mengulang aturan penyamaran yang sama.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => MaskedAuditPayload::class,
            'new_values' => MaskedAuditPayload::class,
        ];
    }

    /**
     * Penegakan sifat append-only audit log.
     *
     * Penolakan diletakkan pada model, bukan pada satu service penulis, karena sebagian besar
     * pemanggil menulis audit langsung lewat Eloquent tanpa melalui service.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ImmutableAuditLogException::untukPembaruan();
        });

        static::deleting(function (): void {
            throw ImmutableAuditLogException::untukPenghapusan();
        });
    }
}
