<?php

namespace App\Jobs;

use App\Actions\Employees\ExecuteImportBatchAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ImportEmployeeBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Batasi lock dispatch agar kegagalan worker tidak menahan batch tanpa batas. */
    public int $uniqueFor = 3600;

    /** Import boleh dicoba ulang untuk kegagalan sementara; checkpoint mencegah duplikasi baris. */
    public int $tries = 3;

    /** Harus lebih pendek dari retry_after koneksi queue agar dua worker tidak memproses batch bersamaan. */
    public int $timeout = 120;

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

        if (($result['executed'] ?? false) !== true) {
            return;
        }

        // Kirim notifikasi in-app melalui NotificationService jika user memiliki employee record
        $employee = $user?->employee;
        if ($employee) {
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
        }
    }

    /** Batch id menjadi identitas unik job untuk lapisan deduplikasi antrean. */
    public function uniqueId(): string
    {
        return $this->batchId;
    }

    /**
     * Handle job failure – update cache status and notify user.
     */
    public function failed(\Throwable $exception): void
    {
        $batchRecord = ImportBatch::query()->find($this->batchId);

        // Callback redelivery yang terlambat tidak boleh menimpa hasil batch yang sudah terminal.
        if ($batchRecord === null || ! in_array($batchRecord->status, ['queued', 'processing', 'failed'], true)) {
            return;
        }

        // Pastikan laporan permanen ikut menandai kegagalan (no-op bila record belum ada).
        ImportBatch::whereKey($this->batchId)
            ->whereIn('status', ['queued', 'processing', 'failed'])
            ->update([
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
