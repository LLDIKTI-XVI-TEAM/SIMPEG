<?php

namespace App\Console\Commands;

use App\Models\StorageRecoveryTask;
use App\Services\StorageRecoveryService;
use Illuminate\Console\Command;

final class RetryStorageRecoveryTasks extends Command
{
    protected $signature = 'storage:retry-recovery
        {--limit=100 : Jumlah maksimum task recovery yang diproses dalam satu eksekusi}';

    protected $description = 'Retry cleanup storage sensitif yang tercatat pada manifest durable';

    public function __construct(private readonly StorageRecoveryService $recovery)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if ($limit === false) {
            $this->error('Opsi --limit wajib berupa bilangan 1 sampai 1000.');

            return self::FAILURE;
        }

        $ids = StorageRecoveryTask::query()
            ->where(function ($query): void {
                $query->where(function ($delete): void {
                    $delete->where('operation', StorageRecoveryTask::OPERATION_DELETE)
                        ->where('status', StorageRecoveryTask::STATUS_PENDING);
                })->orWhere(function ($migrationTarget): void {
                    $migrationTarget->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                        ->where('status', StorageRecoveryTask::STATUS_PREPARED);
                })->orWhere(function ($creationTarget): void {
                    // Grace memberi transaksi pembuat waktu mengambil row lock sebelum worker mencoba cleanup.
                    $creationTarget->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
                        ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                        ->where('created_at', '<=', now()->subSeconds(StorageRecoveryService::CREATION_TARGET_GRACE_SECONDS));
                });
            })
            // Task yang pernah ditunda dipindahkan ke belakang agar batch bounded tidak
            // terus memilih kandidat lama yang sama dan membuat recovery baru kelaparan.
            ->orderByRaw('COALESCE(last_attempted_at, created_at)')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $completed = 0;
        $deferred = 0;
        $failed = 0;

        foreach ($ids as $id) {
            if ($this->recovery->attempt((string) $id)) {
                $completed++;

                continue;
            }

            $task = StorageRecoveryTask::query()->findOrFail($id);
            if (in_array($task->status, [StorageRecoveryTask::STATUS_PENDING, StorageRecoveryTask::STATUS_PREPARED], true)
                && $task->last_error === StorageRecoveryService::DEFERRED_REFERENCE_ERROR) {
                $deferred++;
            } else {
                $failed++;
            }
        }

        $manualReview = StorageRecoveryTask::query()
            ->where('status', StorageRecoveryTask::STATUS_MANUAL_REVIEW)
            ->count();
        $remaining = StorageRecoveryTask::query()
            ->where(function ($query): void {
                $query->where(function ($delete): void {
                    $delete->where('operation', StorageRecoveryTask::OPERATION_DELETE)
                        ->where('status', StorageRecoveryTask::STATUS_PENDING);
                })->orWhere(function ($migrationTarget): void {
                    $migrationTarget->where('operation', StorageRecoveryTask::OPERATION_MIGRATION_TARGET)
                        ->where('status', StorageRecoveryTask::STATUS_PREPARED);
                })->orWhere(function ($creationTarget): void {
                    $creationTarget->where('operation', StorageRecoveryTask::OPERATION_CREATION_TARGET)
                        ->where('status', StorageRecoveryTask::STATUS_PREPARED)
                        ->where('created_at', '<=', now()->subSeconds(StorageRecoveryService::CREATION_TARGET_GRACE_SECONDS));
                });
            })
            ->whereNotIn('id', $ids)
            ->count();

        $this->line("Ringkasan recovery: selesai={$completed}, ditunda_referensi={$deferred}, gagal={$failed}, manual_review={$manualReview}, belum_diproses={$remaining}.");

        return $failed > 0 || $manualReview > 0 || $remaining > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
