<?php

namespace App\Jobs;

use App\Actions\Employees\ExecuteImportBatchAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
    public function handle(ExecuteImportBatchAction $action, NotificationService $notificationService): void
    {
        $user = $this->userId ? User::find($this->userId) : null;
        $result = $action->execute($this->batchId, $user, $this->ipAddress, $this->userAgent);

        // Kirim notifikasi in-app melalui NotificationService jika user memiliki employee record
        $employee = $user?->employee;
        if ($employee) {
            $this->sendSuccessNotification($notificationService, $employee, $result);
        }
    }

    /**
     * Rekam notifikasi sukses bersama marker batch agar retry setelah notifikasi
     * gagal tidak mengulang impor, tetapi tetap mencoba mengirim notifikasi.
     *
     * @param  array{inserted?: int, skipped?: int, failed?: int}  $result
     */
    private function sendSuccessNotification(NotificationService $notificationService, Employee $employee, array $result): void
    {
        DB::transaction(function () use ($notificationService, $employee, $result): void {
            $batch = ImportBatch::query()->lockForUpdate()->find($this->batchId);
            if ($batch === null) {
                return;
            }

            $executionState = $batch->execution_state ?? [];
            if (isset($executionState['success_notification_completed_at'])) {
                return;
            }

            $inserted = $result['inserted'] ?? 0;
            $skipped = $result['skipped'] ?? 0;
            $failed = $result['failed'] ?? 0;

            $notificationService->createForEmployee(
                $employee,
                'import_pegawai',
                'Import Pegawai Selesai',
                "Import selesai: {$inserted} berhasil ditambahkan, {$skipped} di-skip (duplikat), {$failed} gagal.",
                [
                    'inserted' => $inserted,
                    'skipped' => $skipped,
                    'failed' => $failed,
                    'batch_id' => $this->batchId,
                    'url' => route('data-pegawai'),
                ]
            );

            $executionState['success_notification_completed_at'] = now()->toISOString();
            $batch->execution_state = $executionState;
            $batch->save();
        });
    }

    /**
     * Handle job failure – update cache status and notify user.
     */
    public function failed(\Throwable $exception): void
    {
        $importBatch = ImportBatch::find($this->batchId);
        if ($importBatch?->status === 'completed') {
            return;
        }

        // Pastikan laporan permanen ikut menandai kegagalan (no-op bila record belum ada).
        ImportBatch::whereKey($this->batchId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);

        // Update cache status ke failed jika job gagal sepenuhnya
        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$this->batchId);
        if ($batch) {
            $batch['status'] = 'failed';
            $batch['error_message'] = $exception->getMessage();
            Cache::put(UploadImportBatchAction::CACHE_PREFIX.$this->batchId, $batch, now()->addMinutes(10));
        }

        // Kirim notifikasi error in-app via NotificationService
        $user = $this->userId ? User::find($this->userId) : null;
        $employee = $user?->employee;
        if ($employee) {
            $notificationService = app(NotificationService::class);
            $notificationService->createForEmployee(
                $employee,
                'import_pegawai_gagal',
                'Import Pegawai Gagal',
                'Proses import pegawai gagal: '.$exception->getMessage(),
                [
                    'batch_id' => $this->batchId,
                    'url' => route('pegawai.import'),
                ]
            );
        }
    }
}
