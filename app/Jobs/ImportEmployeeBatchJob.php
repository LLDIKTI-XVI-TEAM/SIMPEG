<?php

namespace App\Jobs;

use App\Actions\Employees\ExecuteImportBatchAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Models\SimpegNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

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
        $result = $action->execute($this->batchId, $user, $this->ipAddress, $this->userAgent);

        // Kirim notifikasi in-app jika user memiliki employee record
        $employeeId = $user?->employee_id;
        if ($employeeId) {
            $inserted = $result['inserted'] ?? 0;
            $skipped = $result['skipped'] ?? 0;
            $failed = $result['failed'] ?? 0;

            SimpegNotification::create([
                'user_id' => $employeeId,
                'type' => 'import_pegawai',
                'title' => 'Import Pegawai Selesai',
                'body' => "Import selesai: {$inserted} berhasil ditambahkan, {$skipped} di-skip (duplikat), {$failed} gagal.",
                'data' => [
                    'inserted' => $inserted,
                    'skipped' => $skipped,
                    'failed' => $failed,
                    'batch_id' => $this->batchId,
                ],
                'is_read' => false,
            ]);
        }
    }

    /**
     * Handle job failure – update cache status and notify user.
     */
    public function failed(\Throwable $exception): void
    {
        // Update cache status ke failed jika job gagal sepenuhnya
        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$this->batchId);
        if ($batch) {
            $batch['status'] = 'failed';
            $batch['error_message'] = $exception->getMessage();
            Cache::put(UploadImportBatchAction::CACHE_PREFIX.$this->batchId, $batch, now()->addMinutes(10));
        }

        // Kirim notifikasi error in-app
        $user = $this->userId ? User::find($this->userId) : null;
        $employeeId = $user?->employee_id;
        if ($employeeId) {
            SimpegNotification::create([
                'user_id' => $employeeId,
                'type' => 'import_pegawai_gagal',
                'title' => 'Import Pegawai Gagal',
                'body' => 'Proses import pegawai gagal: '.$exception->getMessage(),
                'data' => ['batch_id' => $this->batchId],
                'is_read' => false,
            ]);
        }
    }
}
