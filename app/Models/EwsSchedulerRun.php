<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EwsSchedulerRun extends Model
{
    protected $table = 'ews_scheduler_runs';

    protected $fillable = [
        'status',
        'started_at',
        'finished_at',
        'alerts_created',
        'employees_checked',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at'       => 'datetime',
            'finished_at'      => 'datetime',
            'alerts_created'   => 'integer',
            'employees_checked'=> 'integer',
        ];
    }

    /**
     * Get the latest scheduler run, or null if never run.
     */
    public static function latestRun(): ?self
    {
        return self::query()->latest('started_at')->first();
    }
}
