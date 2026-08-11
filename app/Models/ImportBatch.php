<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekaman hasil eksekusi import pegawai.
 *
 * Id memakai batch id dari wizard import sehingga laporan dapat diunduh
 * dengan tautan yang sama walaupun cache wizard sudah kedaluwarsa.
 */
class ImportBatch extends Model
{
    use HasUuid;

    protected $fillable = [
        'id',
        'user_id',
        'filename',
        'type',
        'status',
        'total_rows',
        'valid_count',
        'inserted_count',
        'skipped_count',
        'failed_count',
        'row_issues',
        'execution_state',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'row_issues' => 'array',
            'execution_state' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
