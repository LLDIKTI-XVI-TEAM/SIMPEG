<?php

namespace Tests\Feature;

use App\Actions\Notifications\RecoverStaleWhatsAppDispatchesAction;
use App\Models\Employee;
use App\Models\WhatsAppNotificationDelivery;
use App\Models\WhatsAppNotificationOutbox;
use App\Services\Notifications\WhatsApp\WhatsAppNotificationJobPublisher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppDispatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private WhatsAppNotificationJobPublisher $publisher;

    public function test_publish_broker_gagal_meninggalkan_outbox_yang_dapat_dipulihkan_tepat_sekali(): void
    {
        $outbox = $this->persistOutbox(now()->subMinutes(10));
        $observer = $this->observePublications(failuresBeforeSuccess: 1);

        $this->assertFalse($this->publisher()->publish($outbox->id));
        $failed = $outbox->fresh();
        $this->assertNotNull($failed);
        $this->assertNull($failed->published_at);
        $this->assertSame(1, $failed->publish_attempts);

        $result = $this->recover();
        $this->assertSame(1, $result['scanned']);
        $this->assertSame(1, $result['published']);
        $this->assertSame(1, $observer->publishedCount());
        $this->assertNotNull($outbox->fresh()?->published_at);

        $this->assertSame(0, $this->recover()['published']);
        $this->assertSame(1, $observer->publishedCount());
    }

    public function test_reconciler_hanya_mempublikasikan_outbox_yang_stale_dan_terjadwal(): void
    {
        $observer = $this->observePublications();
        $stale = $this->persistOutbox(now()->subMinutes(10));
        $fresh = $this->persistOutbox(now());
        $leased = $this->persistOutbox(now()->subMinutes(10));
        $leased->forceFill(['publish_lease_expires_at' => now()->addMinute()])->save();

        $result = $this->recover(limit: 1);
        $this->assertSame(1, $result['published']);
        $this->assertNotNull($stale->fresh()?->published_at);
        $this->assertNull($fresh->fresh()?->published_at);
        $this->assertNull($leased->fresh()?->published_at);
        $this->assertSame(1, $observer->publishedCount());

        $event = collect(Schedule::events())
            ->first(fn ($event): bool => str_contains($event->command ?? '', 'whatsapp:recover-dispatches'));
        $this->assertNotNull($event);
        $this->assertStringContainsString('--limit=50', $event->command);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_outbox_gagal_permanen_mencapai_batas_dan_tidak_dipublish_tanpa_akhir(): void
    {
        $outbox = $this->persistOutbox(now()->subMinutes(10));
        $observer = $this->observePublications(failuresBeforeSuccess: 3);
        $publisher = $this->publisher();

        $this->assertFalse($publisher->publish($outbox->id));
        $this->assertFalse($publisher->publish($outbox->id));
        $this->assertFalse($publisher->publish($outbox->id));

        $outbox->refresh();
        $this->assertSame(3, $outbox->publish_attempts);
        $this->assertNotNull($outbox->publish_failed_at);
        $this->assertSame('publish_failed', $outbox->publish_failure_code);
        $delivery = WhatsAppNotificationDelivery::query()->findOrFail($outbox->delivery_id);
        $this->assertSame(WhatsAppNotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('publish_failed', $delivery->failure_code);

        $result = $this->recover();
        $this->assertSame(['scanned' => 0, 'published' => 0], $result);
        $this->assertSame(0, $observer->publishedCount());
    }

    private function persistOutbox(\DateTimeInterface $createdAt): WhatsAppNotificationOutbox
    {
        $employee = Employee::factory()->create();
        $delivery = WhatsAppNotificationDelivery::create([
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'employee_id' => $employee->id,
            'event_key' => 'cuti.disetujui',
            'template_key' => 'simpeg_cuti_status',
            'status' => WhatsAppNotificationDelivery::STATUS_QUEUED,
        ]);
        $outbox = WhatsAppNotificationOutbox::create([
            'delivery_id' => $delivery->id,
            'encrypted_payload' => Crypt::encryptString(json_encode([
                'idempotency_key' => $delivery->idempotency_key,
                'employee_id' => $employee->id,
                'event_key' => 'cuti.disetujui',
                'template_key' => 'simpeg_cuti_status',
                'template_id' => 'template-resmi',
                'language' => 'id',
                'variables' => [],
                'body_variables' => ['1' => 'Pegawai'],
                'button_variables' => ['button_target_url' => 'https://simpeg.example.test/cuti'],
                'leave_request_id' => null,
                'leave_request_step_id' => null,
                'ews_alert_id' => null,
                'variables_map' => ['nama_pegawai' => '1'],
                'leave_request_ids' => [],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $outbox->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $outbox;
    }

    private function observePublications(int $failuresBeforeSuccess = 0): WhatsAppRecoveryObserverQueue
    {
        $observer = new WhatsAppRecoveryObserverQueue($failuresBeforeSuccess);
        $observer->setContainer($this->app);
        $queueFactory = new class($observer) implements QueueFactory
        {
            public function __construct(private readonly WhatsAppRecoveryObserverQueue $queue) {}

            public function connection($name = null): QueueContract
            {
                return $this->queue;
            }
        };
        $this->app->instance(QueueFactory::class, $queueFactory);
        $this->publisher = new WhatsAppNotificationJobPublisher($queueFactory);

        return $observer;
    }

    /** @return array{scanned: int, published: int} */
    private function recover(int $limit = RecoverStaleWhatsAppDispatchesAction::DEFAULT_LIMIT): array
    {
        return new RecoverStaleWhatsAppDispatchesAction($this->publisher())->execute($limit);
    }

    private function publisher(): WhatsAppNotificationJobPublisher
    {
        return $this->publisher;
    }
}

final class WhatsAppRecoveryObserverQueue extends BaseQueue implements QueueContract
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
        return $this->enqueueUsing($job, $this->createPayload($job, $queue ?? 'default', $data), $queue, null, fn (string $payload): int => $this->publish($payload));
    }

    public function pushRaw($payload, $queue = null, array $options = []): int
    {
        return $this->publish($payload);
    }

    public function later($delay, $job, $data = '', $queue = null): int
    {
        return $this->enqueueUsing($job, $this->createPayload($job, $queue ?? 'default', $data, $delay), $queue, $delay, fn (string $payload): int => $this->publish($payload));
    }

    public function pop($queue = null): null
    {
        return null;
    }

    private function publish(string $payload): int
    {
        if ($this->failuresBeforeSuccess > 0) {
            $this->failuresBeforeSuccess--;
            throw new \RuntimeException('Broker WhatsApp tidak tersedia.');
        }

        $this->publishedPayloads[] = $payload;

        return $this->publishedCount();
    }
}
