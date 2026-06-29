<?php

namespace App\Jobs;

use App\Actions\Employees\ExecuteImportBatchAction;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportEmployeeBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $batchId,
        protected ?string $userId,
        protected ?string $ipAddress = null,
        protected ?string $userAgent = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ExecuteImportBatchAction $action): void
    {
        $user = $this->userId ? User::find($this->userId) : null;
        $action->execute($this->batchId, $user, $this->ipAddress, $this->userAgent);
    }
}
