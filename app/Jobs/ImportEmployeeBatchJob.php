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
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportEmployeeBatchJob implements ShouldBeUnique, ShouldQueue, ShouldQueueAfterCommit
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
    protected ?string $processingToken = null;

    /** Fallback per instance membedakan job hasil publish ulang saat backend tidak memberi id pesan. */
    protected ?string $deliveryInstanceId = null;

    /**
     * Membuat job import dengan token kepemilikan dan identitas delivery yang stabil.
     */
    public function __construct(
        protected string $batchId,
        protected ?string $userId,
        protected ?string $ipAddress = null,
        protected ?string $userAgent = null,
        ?string $processingToken = null,
        ?string $deliveryInstanceId = null,
    ) {
        $this->processingToken = $processingToken ?? (string) Str::uuid();
        $this->deliveryInstanceId = $deliveryInstanceId ?? (string) Str::uuid();
    }

    /**
     * Menjalankan batch lalu memulihkan notifikasi completion secara idempoten.
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
            $this->deliveryAttempt(),
            $this->deliveryId(),
        );

        if (($result['executed'] ?? false) !== true && in_array($result['status'] ?? null, ['queued', 'processing'], true)) {
            // Jangan ACK atau jalankan delivery paralel saat lease worker lain masih aktif.
            $this->release((int) ($result['retry_after_seconds'] ?? 1));

            return;
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
     * Menangani kegagalan job tanpa menimpa batch yang sudah selesai atau diambil worker lain.
     */
    public function failed(\Throwable $exception): void
    {
        $user = $this->userId ? User::find($this->userId) : null;
        $isLegacyPayload = ! isset($this->processingToken);
        $processingToken = $this->processingToken();
        $processingDeliveryId = $this->deliveryId();
        $processingAttempt = $this->deliveryAttempt();
        $transitioned = DB::transaction(function () use (
            $user,
            $isLegacyPayload,
            $processingToken,
            $processingDeliveryId,
            $processingAttempt,
        ): bool {
            // CAS ini memastikan callback gagal yang kalah race dari completion tidak memiliki side effect.
            $updated = ImportBatch::query()
                ->whereKey($this->batchId)
                ->where(function ($claimable) use (
                    $isLegacyPayload,
                    $processingToken,
                    $processingDeliveryId,
                    $processingAttempt,
                ): void {
                    $claimable->where(function ($queued) use (
                        $isLegacyPayload,
                        $processingToken,
                        $processingDeliveryId,
                        $processingAttempt,
                    ): void {
                        $queued->where('status', 'queued')
                            ->where(function ($owner) use ($isLegacyPayload, $processingToken): void {
                                $owner->where('processing_token', $processingToken);

                                // Payload lama dapat gagal sebelum claim pertama, saat batch queued belum memiliki token.
                                if ($isLegacyPayload) {
                                    $owner->orWhereNull('processing_token');
                                }
                            })
                            ->where(function ($delivery) use ($processingDeliveryId): void {
                                // Null mencakup callback sebelum claim pertama dan row dari skema lama.
                                $delivery->where('processing_delivery_id', $processingDeliveryId)
                                    ->orWhereNull('processing_delivery_id');
                            })
                            ->where(function ($delivery) use ($processingAttempt): void {
                                // Attempt delivery fisik bersifat monoton; callback lebih rendah selalu stale.
                                $delivery->where('processing_attempt', '<=', $processingAttempt)
                                    ->orWhereNull('processing_attempt');
                            });
                    })->orWhere(function ($processing) use (
                        $processingToken,
                        $processingDeliveryId,
                        $processingAttempt,
                    ): void {
                        // Reservation lebih baru dari pesan fisik yang sama boleh menutup owner lama;
                        // arah monoton menolak callback attempt lama tanpa menunggu lease kedaluwarsa.
                        $processing->where('status', 'processing')
                            ->where('processing_token', $processingToken)
                            ->where('processing_delivery_id', $processingDeliveryId)
                            ->where('processing_attempt', '<=', $processingAttempt);
                    });
                })
                ->update([
                    'status' => 'failed',
                    'processing_token' => null,
                    'processing_delivery_id' => null,
                    'processing_attempt' => null,
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

    /** Nomor delivery queue membedakan callback lama dari worker aktif dengan token job yang sama. */
    private function deliveryAttempt(): int
    {
        return max(1, $this->attempts());
    }

    /** Fingerprint backend queue membedakan pesan fisik tanpa menyimpan identifier broker mentah. */
    private function deliveryId(): string
    {
        if ($this->job !== null) {
            $jobId = trim((string) $this->job->getJobId());

            if ($jobId !== '') {
                return hash('sha256', implode("\0", [
                    (string) $this->job->getConnectionName(),
                    (string) $this->job->getQueue(),
                    $jobId,
                ]));
            }
        }

        return hash('sha256', "fallback\0".$this->deliveryInstanceId());
    }

    /** Payload lama mendapat fallback deterministik agar fresh unserialize tetap merujuk delivery yang sama. */
    private function deliveryInstanceId(): string
    {
        if (! isset($this->deliveryInstanceId)) {
            $this->deliveryInstanceId = hash('sha256', implode("\0", [
                'legacy',
                $this->batchId,
                $this->processingToken(),
            ]));
        }

        return $this->deliveryInstanceId;
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
