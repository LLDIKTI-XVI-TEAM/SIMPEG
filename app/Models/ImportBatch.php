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
        'processed_valid_count',
        'row_issues',
        'error_message',
        'execution_payload',
        'processing_token',
        'processing_delivery_id',
        'processing_attempt',
        'lease_expires_at',
        'completion_notified_at',
        'failure_notified_at',
        'job_publish_attempted_at',
        'job_published_at',
        'job_publish_lease_expires_at',
        'job_publish_attempts',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'row_issues' => 'array',
            'execution_payload' => 'encrypted:array',
            'processed_valid_count' => 'integer',
            'processing_attempt' => 'integer',
            'lease_expires_at' => 'datetime',
            'completion_notified_at' => 'datetime',
            'failure_notified_at' => 'datetime',
            'job_publish_attempted_at' => 'datetime',
            'job_published_at' => 'datetime',
            'job_publish_lease_expires_at' => 'datetime',
            'job_publish_attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
