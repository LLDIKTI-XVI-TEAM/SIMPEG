<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Manifest durable untuk retry cleanup storage tanpa bergantung pada queue backend. */
class StorageRecoveryTask extends Model
{
    use HasUuid;

    public const OPERATION_DELETE = 'delete';

    public const OPERATION_MIGRATION_TARGET = 'migration_target';

    public const OPERATION_CREATION_TARGET = 'creation_target';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MANUAL_REVIEW = 'manual_review';

    public const STATUS_PREPARED = 'prepared';

    public const STATUS_ADOPTED = 'adopted';

    protected $fillable = [
        'idempotency_key',
        'operation',
        'status',
        'category',
        'disk',
        'path',
        'owner_id',
        'source_disk',
        'source_path',
        'sha256',
        'attempts',
        'last_error',
        'last_attempted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
