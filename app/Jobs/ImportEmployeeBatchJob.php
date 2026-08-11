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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportEmployeeBatchJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Batasi lock dispatch agar kegagalan worker tidak menahan batch tanpa batas. */
    public int $uniqueFor = 3600;

    /** Import boleh dicoba ulang untuk kegagalan sementara; checkpoint mencegah duplikasi baris. */
    public int $tries = 3;

    /** Harus lebih pendek dari retry_after koneksi queue agar dua worker tidak memproses batch bersamaan. */
    public int $timeout = 120;

    private const FAILURE_MESSAGE = 'Proses import pegawai gagal. Silakan coba kembali atau hubungi administrator.';

    /** Token ini ikut diserialisasi bersama job agar seluruh redelivery memiliki ownership yang sama. */
    final protected ?string $processingToken = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $batchId,
        protected ?string $userId,
        protected ?string $ipAddress = null,
        protected ?string $userAgent = null,
        ?string $processingToken = null,
    ) {
        $this->processingToken = $processingToken ?? (string) Str::uuid();
    }

    /**
     * Execute the job.
     */
    public function handle(ExecuteImportBatchAction $action, NotificationService $notificationService): void
    {
        $user = $this->userId ? User::find($this->userId) : null;
        $result = $action->execute(
            $this->batchId,
            $user,
            $this->ipAddress,
            $this->userAgent,
            $this->processingToken(),
        );

        if (($result['executed'] ?? false) !== true && in_array($result['status'] ?? null, ['queued', 'processing'], true)) {
            // Jangan ACK pesan ketika batch masih non-terminal dan dimiliki token lain.
            throw new \RuntimeException('Batch import masih dimiliki worker lain.');
        }

        // Redelivery completed tetap masuk jalur ini untuk memulihkan crash setelah commit completion.
        $this->notifyCompletionOnce($user, $notificationService);
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
        $user = $this->userId ? User::find($this->userId) : null;
        $isLegacyPayload = ! isset($this->processingToken);
        $processingToken = $this->processingToken();
        $transitioned = DB::transaction(function () use ($user, $isLegacyPayload, $processingToken): bool {
            // CAS ini memastikan callback gagal yang kalah race dari completion tidak memiliki side effect.
            $updated = ImportBatch::query()
                ->whereKey($this->batchId)
                ->whereIn('status', ['queued', 'processing'])
                ->where(function ($owner) use ($isLegacyPayload, $processingToken): void {
                    $owner->where('processing_token', $processingToken);

                    // Payload lama dapat gagal sebelum claim pertama, saat batch queued belum memiliki token.
                    if ($isLegacyPayload) {
                        $owner->orWhere(function ($unowned): void {
                            $unowned->where('status', 'queued')
                                ->whereNull('processing_token');
                        });
                    }
                })
                ->update([
                    'status' => 'failed',
                    'processing_token' => null,
                    'lease_expires_at' => null,
                    'error_message' => self::FAILURE_MESSAGE,
                    'finished_at' => now(),
                ]);

            if ($updated !== 1) {
                return false;
            }

            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($this->batchId);
            if ($batch->failure_notified_at === null) {
                $employee = $user?->employee;
                if ($employee !== null) {
                    app(NotificationService::class)->createForEmployee(
                        $employee,
                        'import_pegawai_gagal',
                        'Import Pegawai Gagal',
                        'Proses import pegawai gagal. Silakan periksa laporan import atau hubungi administrator.',
                        [
                            'batch_id' => $this->batchId,
                            'url' => route('pegawai.import'),
                        ]
                    );
                }

                $batch->forceFill(['failure_notified_at' => now()])->save();
            }

            return true;
        });

        if (! $transitioned) {
            return;
        }

        // Detail exception hanya berada di log server; laporan dan notifikasi pengguna memakai pesan generik.
        Log::error('Job import pegawai gagal setelah retry maksimum.', [
            'batch_id' => $this->batchId,
            'exception_class' => $exception::class,
        ]);

        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$this->batchId) ?? [];
        $batch['status'] = 'failed';
        $batch['error_message'] = self::FAILURE_MESSAGE;
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$this->batchId, $batch, now()->addMinutes(10));
    }

    /** Payload lama memakai batch id agar ownership stabil pada setiap fresh unserialize dan redelivery. */
    private function processingToken(): string
    {
        if (! isset($this->processingToken)) {
            $this->processingToken = $this->batchId;
        }

        return $this->processingToken;
    }

    /** Marker dan record notifikasi completion commit bersama agar redelivery tidak menggandakan notifikasi. */
    private function notifyCompletionOnce(?User $user, NotificationService $notificationService): void
    {
        DB::transaction(function () use ($user, $notificationService): void {
            $batch = ImportBatch::query()->lockForUpdate()->find($this->batchId);
            if ($batch === null || $batch->status !== 'completed' || $batch->completion_notified_at !== null) {
                return;
            }

            $employee = $user?->employee;
            if ($employee !== null) {
                $notificationService->createForEmployee(
                    $employee,
                    'import_pegawai',
                    'Import Pegawai Selesai',
                    "Import selesai: {$batch->inserted_count} berhasil ditambahkan, {$batch->skipped_count} di-skip (duplikat), {$batch->failed_count} gagal.",
                    [
                        'inserted' => $batch->inserted_count,
                        'skipped' => $batch->skipped_count,
                        'failed' => $batch->failed_count,
                        'batch_id' => $this->batchId,
                        'url' => route('data-pegawai'),
                    ]
                );
            }

            $batch->forceFill(['completion_notified_at' => now()])->save();
        });
    }
}
