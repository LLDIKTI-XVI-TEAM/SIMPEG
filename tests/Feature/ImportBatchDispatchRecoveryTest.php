<?php

namespace Tests\Feature;

use App\Actions\Employees\QueueImportBatchAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\TestCase;

class ImportBatchDispatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** Kegagalan broker harus meninggalkan claim queued yang dapat dipublish tepat sekali oleh recovery. */
    public function test_publish_exception_leaves_queued_batch_recoverable_once(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->cacheValidatedBatch($user);
        $observer = $this->observePublications(failuresBeforeSuccess: 1);
        $thrown = null;

        try {
            app(QueueImportBatchAction::class)->execute($batchId, $user);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNull($thrown, 'Detail kegagalan broker tidak boleh membatalkan claim durable ke pengguna.');
        $this->assertDatabaseHas('import_batches', ['id' => $batchId, 'status' => 'queued']);
        $failedPublish = ImportBatch::query()->findOrFail($batchId);
        $this->assertNull($failedPublish->job_published_at);
        $this->assertSame(1, $failedPublish->job_publish_attempts);

        $this->runRecoveryCommand();
        $this->assertSame(1, $observer->publishedCount());
        $recovered = ImportBatch::query()->findOrFail($batchId);
        $this->assertNotNull($recovered->job_published_at);
        $this->assertSame(2, $recovered->job_publish_attempts);

        $this->runRecoveryCommand();
        $this->assertSame(1, $observer->publishedCount());
        $this->assertSame(2, ImportBatch::query()->findOrFail($batchId)->job_publish_attempts);
    }

    /** Reconciler bounded hanya mempublish batch queued tanpa marker yang sudah stale. */
    public function test_stale_unpublished_batch_is_recovered_without_republishing_ineligible_batches(): void
    {
        $observer = $this->observePublications();
        $staleQueued = $this->persistBatch('queued', now()->subMinutes(10));
        $secondStaleQueued = $this->persistBatch('queued', now()->subMinutes(9));
        $freshQueued = $this->persistBatch('queued', now());
        $processing = $this->persistBatch('processing', now()->subMinutes(10), now()->subMinute());
        $completed = $this->persistBatch('completed', now()->subMinutes(10));
        $leasedQueued = $this->persistBatch('queued', now()->subMinutes(10));
        $leasedQueued->forceFill([
            'job_publish_attempted_at' => now()->subMinute(),
            'job_publish_lease_expires_at' => now()->addMinutes(4),
        ])->save();

        $this->runRecoveryCommand(limit: 1);

        $this->assertSame(1, $observer->publishedCount());
        $this->assertNotNull($staleQueued->fresh()?->job_published_at);
        $this->assertNull($secondStaleQueued->fresh()?->job_published_at);
        $this->assertNull($freshQueued->fresh()?->job_published_at);
        $this->assertNull($processing->fresh()?->job_published_at);
        $this->assertNull($completed->fresh()?->job_published_at);
        $this->assertNull($leasedQueued->fresh()?->job_published_at);

        $this->runRecoveryCommand(limit: 1);
        $this->assertSame(2, $observer->publishedCount());
        $this->assertNotNull($secondStaleQueued->fresh()?->job_published_at);

        $this->runRecoveryCommand(limit: 1);
        $this->assertSame(2, $observer->publishedCount());
    }

    public function test_recovery_command_is_scheduled_bounded_with_overlap_protection(): void
    {
        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'import:recover-dispatches'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('--limit=50', $event->command);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
        $this->assertSame(config('app.timezone'), $event->timezone);
    }

    private function runRecoveryCommand(int $limit = 50): void
    {
        try {
            $this->artisan("import:recover-dispatches --limit={$limit}")
                ->assertExitCode(0);
        } catch (CommandNotFoundException) {
            $this->fail('Command recovery dispatch import belum tersedia.');
        }
    }

    private function cacheValidatedBatch(User $user): string
    {
        $batchId = (string) Str::uuid();
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, [
            'user_id' => $user->id,
            'filename' => 'employees.csv',
            'type' => 'utama',
            'rows' => [],
            'total_rows' => 0,
            'validation' => [
                'valid_count' => 0,
                'skip_count' => 0,
                'error_count' => 0,
                'results' => [],
            ],
        ], now()->addHour());

        return $batchId;
    }

    private function persistBatch(string $status, \DateTimeInterface $createdAt, ?\DateTimeInterface $startedAt = null): ImportBatch
    {
        $batch = new ImportBatch([
            'id' => (string) Str::uuid(),
            'filename' => 'employees.csv',
            'type' => 'utama',
            'status' => $status,
            'total_rows' => 0,
            'valid_count' => 0,
            'processed_valid_count' => 0,
            'processing_token' => (string) Str::uuid(),
            'execution_payload' => [
                'filename' => 'employees.csv',
                'type' => 'utama',
                'validation' => ['valid_count' => 0, 'skip_count' => 0, 'error_count' => 0, 'results' => []],
            ],
            'started_at' => $startedAt,
        ]);
        $batch->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $batch;
    }

    private function observePublications(int $failuresBeforeSuccess = 0): RecoveryPublishObserverQueue
    {
        $connection = 'recovery-publish-observer-'.Str::uuid();
        $observer = new RecoveryPublishObserverQueue($failuresBeforeSuccess);

        Queue::extend(
            $connection,
            static fn (): ConnectorInterface => new RecoveryPublishObserverConnector($observer),
        );
        config([
            'queue.default' => $connection,
            "queue.connections.{$connection}" => ['driver' => $connection],
        ]);

        return $observer;
    }
}

/** Queue test-only yang merekam publish broker dan dapat mensimulasikan kegagalan koneksi. */
final class RecoveryPublishObserverQueue extends BaseQueue implements QueueContract
{
    /** @var list<string> */
    private array $publishedPayloads = [];

    public function __construct(private int $failuresBeforeSuccess = 0) {}

    public function publishedCount(): int
    {
        return count($this->publishedPayloads);
    }

    public function size($queue = null): int
    {
        return $this->publishedCount();
    }

    public function push($job, $data = '', $queue = null): int
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?? 'default', $data),
            $queue,
            null,
            fn (string $payload): int => $this->publish($payload),
        );
    }

    public function pushRaw($payload, $queue = null, array $options = []): int
    {
        return $this->publish($payload);
    }

    public function later($delay, $job, $data = '', $queue = null): int
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?? 'default', $data, $delay),
            $queue,
            $delay,
            fn (string $payload): int => $this->publish($payload),
        );
    }

    public function pop($queue = null): null
    {
        return null;
    }

    private function publish(string $payload): int
    {
        if ($this->failuresBeforeSuccess > 0) {
            $this->failuresBeforeSuccess--;

            throw new \RuntimeException('Broker import tidak tersedia.');
        }

        $this->publishedPayloads[] = $payload;

        return $this->publishedCount();
    }
}

/** Connector test-only untuk observer recovery publish import. */
final class RecoveryPublishObserverConnector implements ConnectorInterface
{
    public function __construct(private readonly RecoveryPublishObserverQueue $queue) {}

    public function connect(array $config): QueueContract
    {
        return $this->queue;
    }
}
