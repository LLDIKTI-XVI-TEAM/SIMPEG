<?php

namespace Tests\Feature;

use App\Actions\Employees\DownloadImportReportAction;
use App\Actions\Employees\ExecuteImportBatchAction;
use App\Actions\Employees\QueueImportBatchAction;
use App\Actions\Employees\UploadImportBatchAction;
use App\Actions\Employees\ValidateImportBatchAction;
use App\Jobs\ImportEmployeeBatchJob;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\SimpegNotification;
use App\Models\User;
use App\Services\NotificationService;
use Carbon\CarbonInterface;
use Database\Seeders\RbacSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\Queue as BaseQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmployeeImportExecutionRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(RbacSeeder::class);
        Cache::flush();
    }

    /**
     * NIP yang muncul setelah validasi harus menjadi satu outcome skip pada seluruh hasil import.
     */
    public function test_execution_time_duplicate_nip_is_counted_as_skipped_everywhere(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->validCsv()),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];

        $validation = app(ValidateImportBatchAction::class)->execute($batchId, null, $user);
        $this->assertSame(1, $validation['valid_count']);
        $this->assertSame(0, $validation['skip_count']);

        $employeeCountBeforeRace = Employee::count();
        Employee::factory()->create(['nip' => '198001012006041001']);
        $this->persistQueuedBatch($batchId, $user);

        $result = app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $this->assertDatabaseCount('employees', $employeeCountBeforeRace + 1);
        $this->assertSame(0, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['inserted_count']);
        $this->assertSame(1, $result['skipped_count']);

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame($result['inserted_count'], $persistedBatch->inserted_count);
        $this->assertSame($result['skipped_count'], $persistedBatch->skipped_count);
        $this->assertSame([
            [
                'row' => 2,
                'nama' => 'Budi Santoso',
                'kategori' => 'dilewati',
                'errors' => [
                    'NIP' => ['NIP sudah terdaftar saat proses import dijalankan.'],
                ],
            ],
        ], $persistedBatch->row_issues);

        $cachedBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
        $this->assertSame(0, $cachedBatch['result']['inserted']);
        $this->assertSame(1, $cachedBatch['result']['skipped']);
        $this->assertSame($persistedBatch->row_issues, $cachedBatch['row_issues']);

        $audit = AuditLog::query()
            ->where('event', 'IMPORT')
            ->where('auditable_type', 'Employee')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame(0, $audit->new_values['total_inserted']);
        $this->assertSame(1, $audit->new_values['total_skipped']);

        $this->actingAs($user);
        $report = $this->get("/pegawai/import/{$batchId}/laporan");
        $report->assertOk();
        $csv = $report->streamedContent();
        $this->assertStringContainsString('"Berhasil ditambahkan",0', $csv);
        $this->assertStringContainsString('"Dilewati (NIP terdaftar)",1', $csv);
    }

    /** Pelanggaran unique selain NIP harus dilempar ulang dan baru terminal setelah retry habis. */
    public function test_execution_does_not_classify_another_unique_constraint_as_duplicate_nip(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->validCsv('Budi employees_nip_unique Santoso')),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];

        $validation = app(ValidateImportBatchAction::class)->execute($batchId, null, $user);
        $this->assertSame(1, $validation['valid_count']);

        DB::statement(
            'CREATE UNIQUE INDEX employee_import_race_email_unique '
            .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
        );
        Employee::factory()->create([
            'nip' => '199001012015041003',
            'email_pribadi' => 'budi@example.com',
        ]);
        $jobToken = (string) Str::uuid();
        $this->persistQueuedBatch($batchId, $user, $jobToken);

        $caughtException = null;
        try {
            app(ExecuteImportBatchAction::class)->execute($batchId, $user, null, null, $jobToken);
            $this->fail('Unique violation email seharusnya dilempar ulang.');
        } catch (QueryException $exception) {
            $caughtException = $exception;
            $this->assertStringContainsString('employee_import_race_email_unique', $exception->getMessage());
        }

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('queued', $persistedBatch->status);
        $this->assertSame(0, $persistedBatch->inserted_count);
        $this->assertSame(0, $persistedBatch->skipped_count);

        (new ImportEmployeeBatchJob($batchId, $user->id, null, null, $jobToken))->failed($caughtException);

        $this->assertSame('failed', ImportBatch::query()->findOrFail($batchId)->status);
    }

    /** Retry worker melanjutkan baris yang belum commit tanpa mengubah hasil attempt pertama menjadi skip. */
    public function test_transient_failure_after_committed_row_resumes_from_durable_checkpoint(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => config('database.default'),
        ]);
        app()->forgetInstance('queue.worker');

        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->twoRowCsv()),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];
        app(ValidateImportBatchAction::class)->execute($batchId, null, $user);

        $temporaryConflict = Employee::factory()->create([
            'nip' => '199001012015041003',
            'email_pribadi' => 'siti@example.com',
        ]);
        DB::statement(
            'CREATE UNIQUE INDEX employee_import_retry_email_unique '
            .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
        );

        app(QueueImportBatchAction::class)->execute($batchId, $user);
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 3));

        $this->assertDatabaseHas('employees', ['nip' => '198001012006041001']);
        $retryableBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('queued', $retryableBatch->status);
        $this->assertSame(1, $retryableBatch->inserted_count);
        $this->assertSame(0, $retryableBatch->skipped_count);
        $this->assertSame(0, $retryableBatch->failed_count);
        $this->assertSame([], $retryableBatch->row_issues);

        $temporaryConflict->forceDelete();
        Cache::flush();

        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 3));

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('completed', $persistedBatch->status);
        $this->assertSame(2, $persistedBatch->inserted_count);
        $this->assertSame(0, $persistedBatch->skipped_count);
        $this->assertSame(0, $persistedBatch->failed_count);
        $this->assertSame(1, Employee::query()->where('nip', '198001012006041001')->count());
        $this->assertSame(1, Employee::query()->where('nip', '198101012007041002')->count());
        $this->assertSame(1, AuditLog::query()->where('event', 'IMPORT')->count());
        $this->assertDatabaseCount('jobs', 0);
    }

    /** Worker baru harus dapat mengambil alih batch jika worker lama melewati batas lease. */
    public function test_expired_processing_lease_is_reclaimed_by_a_new_attempt(): void
    {
        $this->assertTrue(
            Schema::hasColumn('import_batches', 'lease_expires_at'),
            'Batch import belum memiliki lease durable untuk pemulihan setelah hard crash.',
        );
        $this->assertTrue(
            Schema::hasColumn('import_batches', 'processing_token'),
            'Batch import belum memiliki token attempt untuk compare-and-swap.',
        );

        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);
        ImportBatch::query()->whereKey($batchId)->update([
            'status' => 'processing',
            'processing_token' => (string) Str::uuid(),
            'lease_expires_at' => now()->subSecond(),
        ]);

        $result = app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $this->assertTrue($result['executed']);
        $this->assertSame('completed', $result['status']);
        $this->assertDatabaseHas('employees', ['nip' => '198001012006041001']);
        $this->assertSame('completed', ImportBatch::query()->findOrFail($batchId)->status);
    }

    /** Redelivery job yang sama harus melanjutkan checkpoint terlambat tanpa menunggu lease kedaluwarsa. */
    public function test_same_job_redelivery_resumes_at_retry_after_while_late_checkpoint_lease_is_active(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->twoRowCsv()),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];
        app(ValidateImportBatchAction::class)->execute($batchId, null, $user);

        $jobToken = (string) Str::uuid();
        $this->persistQueuedBatch($batchId, $user, $jobToken);
        Employee::factory()->create(['nip' => '198001012006041001']);
        ImportBatch::query()->whereKey($batchId)->update([
            'status' => 'processing',
            'processed_valid_count' => 1,
            'inserted_count' => 1,
            'processing_token' => $jobToken,
            // Checkpoint terjadi pada detik ke-119 dan memperpanjang lease hingga detik ke-269.
            'lease_expires_at' => now()->addSeconds(150),
        ]);

        // Pesan muncul lagi pada retry_after detik ke-180; lease masih aktif selama 89 detik.
        $this->travel(61)->seconds();
        $leaseExpiresAt = ImportBatch::query()->findOrFail($batchId)->lease_expires_at;
        $this->assertInstanceOf(CarbonInterface::class, $leaseExpiresAt);
        $this->assertTrue($leaseExpiresAt->isFuture());

        $job = new ImportEmployeeBatchJob($batchId, $user->id, null, null, $jobToken);
        $job->handle(app(ExecuteImportBatchAction::class), app(NotificationService::class));

        $completed = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2, $completed->processed_valid_count);
        $this->assertSame(2, $completed->inserted_count);
        $this->assertDatabaseHas('employees', ['nip' => '198101012007041002']);
    }

    /** Payload job versi lama harus memakai ownership stabil ketika di-unserialize ulang setelah retry. */
    public function test_legacy_serialized_job_resumes_with_stable_fallback_token_after_transient_failure(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->twoRowCsv()),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];
        app(ValidateImportBatchAction::class)->execute($batchId, null, $user);
        $this->persistQueuedBatch($batchId, $user);

        $temporaryConflict = Employee::factory()->create([
            'nip' => '199001012015041003',
            'email_pribadi' => 'siti@example.com',
        ]);
        DB::statement(
            'CREATE UNIQUE INDEX employee_import_legacy_retry_email_unique '
            .'ON employees (LOWER(email_pribadi)) WHERE email_pribadi IS NOT NULL',
        );

        $legacyPayload = $this->legacySerializedJobPayload($batchId, $user->id);
        $this->assertStringNotContainsString('processingToken', $legacyPayload);

        $firstDelivery = unserialize($legacyPayload);
        $this->assertInstanceOf(ImportEmployeeBatchJob::class, $firstDelivery);

        $caught = null;
        try {
            $firstDelivery->handle(app(ExecuteImportBatchAction::class), app(NotificationService::class));
        } catch (\Throwable $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(QueryException::class, $caught);
        $retryable = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('queued', $retryable->status);
        $this->assertSame(1, $retryable->processed_valid_count);
        $this->assertNotNull($retryable->processing_token);

        $temporaryConflict->forceDelete();
        Cache::flush();

        $redelivery = unserialize($legacyPayload);
        $this->assertInstanceOf(ImportEmployeeBatchJob::class, $redelivery);
        $redelivery->handle(app(ExecuteImportBatchAction::class), app(NotificationService::class));

        $completed = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2, $completed->inserted_count);
        $this->assertSame(2, $completed->processed_valid_count);
    }

    /** Callback gagal payload lama boleh menutup batch queued yang belum memiliki token. */
    public function test_legacy_serialized_job_failed_callback_claims_unowned_queued_batch(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);

        $legacyPayload = $this->legacySerializedJobPayload($batchId, $user->id);
        $legacyJob = unserialize($legacyPayload);
        $this->assertInstanceOf(ImportEmployeeBatchJob::class, $legacyJob);

        $legacyJob->failed(new \RuntimeException('worker legacy gagal'));

        $failed = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('failed', $failed->status);
        $this->assertSame(
            'Proses import pegawai gagal. Silakan coba kembali atau hubungi administrator.',
            $failed->error_message,
        );
    }

    /** Delivery dengan token lain tidak boleh ACK batch yang masih dimiliki attempt aktif. */
    public function test_foreign_job_token_does_not_ack_active_processing_batch(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $ownerToken = (string) Str::uuid();
        $this->persistQueuedBatch($batchId, $user, $ownerToken);
        ImportBatch::query()->whereKey($batchId)->update([
            'status' => 'processing',
            'processing_token' => $ownerToken,
            'lease_expires_at' => now()->addMinute(),
        ]);

        $foreignJob = new ImportEmployeeBatchJob($batchId, $user->id, null, null, (string) Str::uuid());

        try {
            $foreignJob->handle(app(ExecuteImportBatchAction::class), app(NotificationService::class));
            $this->fail('Delivery asing seharusnya dilepas ulang, bukan di-ACK sebagai sukses.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Batch import masih dimiliki worker lain.', $exception->getMessage());
        }

        $processing = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('processing', $processing->status);
        $this->assertSame($ownerToken, $processing->processing_token);
    }

    /** Payload sensitif tetap dapat dibaca model, tetapi tidak tampak sebagai plaintext di database. */
    public function test_queued_execution_payload_is_encrypted_at_rest(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        Queue::fake();

        app(QueueImportBatchAction::class)->execute($batchId, $user);

        $rawPayload = DB::table('import_batches')->where('id', $batchId)->value('execution_payload');
        $this->assertIsString($rawPayload);
        $this->assertStringNotContainsString('budi@example.com', $rawPayload);

        $payload = ImportBatch::query()->findOrFail($batchId)->execution_payload;
        $this->assertIsArray($payload);
        $validation = $payload['validation'];
        $this->assertIsArray($validation);
        $results = $validation['results'];
        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $firstResult = $results[0];
        $this->assertIsArray($firstResult);
        $validatedData = $firstResult['validated_data'];
        $this->assertIsArray($validatedData);
        $this->assertSame('budi@example.com', $validatedData['email_pribadi']);
    }

    /** Redelivery completed memulihkan file/cache dan hanya membuat satu notifikasi. */
    public function test_completed_redelivery_recovers_cleanup_cache_and_notifies_once(): void
    {
        $user = $this->notifiableAdmin();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);
        app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $storedPath = UploadImportBatchAction::STORAGE_DIR.'/'.$batchId.'_employees.csv';
        Storage::disk('local')->put($storedPath, 'sisa file setelah crash');
        Cache::flush();

        $job = new ImportEmployeeBatchJob($batchId, $user->id);
        $job->handle(app(ExecuteImportBatchAction::class), app(NotificationService::class));
        $job->handle(app(ExecuteImportBatchAction::class), app(NotificationService::class));

        $this->assertFalse(Storage::disk('local')->exists($storedPath));
        $this->assertSame('completed', Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['status']);
        $this->assertSame(1, Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['result']['inserted']);
        $this->assertNotNull(ImportBatch::query()->findOrFail($batchId)->completion_notified_at);
        $this->assertCount(1, $this->batchNotifications($batchId, 'import_pegawai'));
    }

    /** Setiap mutasi row memiliki audit minimal, sedangkan summary tetap tunggal saat redelivery. */
    public function test_row_and_summary_audits_are_scoped_to_batch_without_employee_pii(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->twoRowCsv()),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];
        app(ValidateImportBatchAction::class)->execute($batchId, null, $user);
        $this->persistQueuedBatch($batchId, $user);

        app(ExecuteImportBatchAction::class)->execute($batchId, $user);
        app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $rowAudits = AuditLog::query()
            ->where('event', 'CREATE')
            ->where('auditable_type', 'Employee')
            ->get()
            ->filter(fn (AuditLog $audit): bool => ($audit->new_values['batch_id'] ?? null) === $batchId)
            ->values();
        $this->assertCount(2, $rowAudits);

        foreach ($rowAudits as $audit) {
            $this->assertSame('row', $audit->new_values['scope']);
            $this->assertSame('inserted', $audit->new_values['outcome']);
            $this->assertNotNull($audit->auditable_id);
            $serialized = json_encode($audit->new_values, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('budi@example.com', $serialized);
            $this->assertStringNotContainsString('siti@example.com', $serialized);
            $this->assertStringNotContainsString('198001012006041001', $serialized);
            $this->assertStringNotContainsString('198101012007041002', $serialized);
        }

        $summaryAudits = AuditLog::query()
            ->where('event', 'IMPORT')
            ->get()
            ->filter(fn (AuditLog $audit): bool => ($audit->new_values['batch_id'] ?? null) === $batchId)
            ->values();
        $this->assertCount(1, $summaryAudits);
        $this->assertSame('batch_summary', $summaryAudits->sole()->new_values['scope']);
    }

    /** Kegagalan audit row harus rollback mutasi pegawai dan checkpoint pada transaksi yang sama. */
    public function test_row_audit_failure_rolls_back_employee_and_checkpoint(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Rollback audit row import diverifikasi khusus pada PostgreSQL.');
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_import_row_audit() RETURNS trigger AS $$
            BEGIN
                IF NEW.event = 'CREATE' AND NEW.new_values->>'scope' = 'row' THEN
                    RAISE EXCEPTION 'forced import row audit failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER reject_import_row_audit_trigger
            BEFORE INSERT ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION reject_import_row_audit();
            SQL);

        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);

        try {
            app(ExecuteImportBatchAction::class)->execute($batchId, $user);
            $this->fail('Eksekusi seharusnya gagal ketika audit row ditolak database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced import row audit failure', $exception->getMessage());
        }

        $retryable = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('queued', $retryable->status);
        $this->assertSame(0, $retryable->processed_valid_count);
        $this->assertSame(0, $retryable->inserted_count);
        $this->assertDatabaseMissing('employees', ['nip' => '198001012006041001']);
    }

    /** Dua request eksekusi untuk batch sama hanya boleh menghasilkan satu dispatch antrean. */
    public function test_repeated_execution_request_claims_and_dispatches_batch_once(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        Queue::fake();

        $this->actingAs($user);
        $first = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);
        $second = $this->postJsonWithCsrf("/api/pegawai/import/{$batchId}/execute", []);

        $first->assertOk()->assertJsonPath('status', 'queued');
        $second->assertOk()->assertJsonPath('status', 'queued');
        Queue::assertPushed(ImportEmployeeBatchJob::class, 1);
        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'status' => 'queued',
        ]);
    }

    /** Queue eksternal tidak boleh menerima job sebelum seluruh transaksi claim commit. */
    public function test_import_job_dispatch_waits_for_outer_transaction_commit(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $observer = $this->observeImportJobPublications();

        DB::transaction(function () use ($batchId, $user, $observer): void {
            app(QueueImportBatchAction::class)->execute($batchId, $user);

            $this->assertSame(0, $observer->publishedCount());
        });

        $this->assertSame(1, $observer->publishedCount());
    }

    /** Rollback claim harus membuang callback dispatch agar tidak ada job yatim. */
    public function test_outer_transaction_rollback_discards_import_job_dispatch(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $observer = $this->observeImportJobPublications();

        try {
            DB::transaction(function () use ($batchId, $user): void {
                app(QueueImportBatchAction::class)->execute($batchId, $user);

                throw new \RuntimeException('Paksa rollback claim import.');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Paksa rollback claim import.', $exception->getMessage());
        }

        $this->assertSame(0, $observer->publishedCount());

        $retry = app(QueueImportBatchAction::class)->execute($batchId, $user);

        $this->assertSame('queued', $retry['status']);
        $this->assertSame(1, $observer->publishedCount());
    }

    /** Redelivery job setelah batch selesai tidak boleh mengubah hasil, audit, atau cache pertama. */
    public function test_repeated_execution_preserves_first_completed_result(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);

        $firstResult = app(ExecuteImportBatchAction::class)->execute($batchId, $user);
        $firstBatch = ImportBatch::query()->findOrFail($batchId)->replicate();
        $firstCacheResult = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['result'];
        $firstAuditCount = AuditLog::query()->where('event', 'IMPORT')->count();

        $secondResult = app(ExecuteImportBatchAction::class)->execute($batchId, $user);
        $persistedBatch = ImportBatch::query()->findOrFail($batchId);

        $this->assertSame($firstResult['inserted'], $secondResult['inserted']);
        $this->assertSame($firstResult['skipped'], $secondResult['skipped']);
        $this->assertSame($firstBatch->inserted_count, $persistedBatch->inserted_count);
        $this->assertSame($firstBatch->skipped_count, $persistedBatch->skipped_count);
        $this->assertSame($firstBatch->row_issues, $persistedBatch->row_issues);
        $this->assertSame($firstCacheResult, Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['result']);
        $this->assertSame($firstAuditCount, AuditLog::query()->where('event', 'IMPORT')->count());
        $this->assertSame(1, Employee::query()->where('nip', '198001012006041001')->count());
    }

    /** Batch yang sudah diproses worker lain tidak boleh diklaim atau dieksekusi ulang. */
    public function test_processing_batch_cannot_be_executed_again(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);
        ImportBatch::query()->whereKey($batchId)->update(['status' => 'processing']);

        app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $this->assertDatabaseMissing('employees', ['nip' => '198001012006041001']);
        $this->assertSame('processing', ImportBatch::query()->findOrFail($batchId)->status);
        $this->assertSame(0, AuditLog::query()->where('event', 'IMPORT')->count());
    }

    /** Callback gagal dari redelivery tidak boleh menimpa cache atau laporan batch yang sudah selesai. */
    public function test_late_failure_callback_preserves_completed_batch_result(): void
    {
        $user = $this->notifiableAdmin();
        $batchId = $this->validatedBatchId($user);
        $this->persistQueuedBatch($batchId, $user);
        app(ExecuteImportBatchAction::class)->execute($batchId, $user);

        $job = new ImportEmployeeBatchJob($batchId, $user->id);
        $job->handle(app(ExecuteImportBatchAction::class), app(NotificationService::class));

        $completedCache = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
        $completionNotificationCount = $this->batchNotifications($batchId, 'import_pegawai')->count();

        $job->failed(new \RuntimeException('Kegagalan redelivery terlambat'));

        $persistedBatch = ImportBatch::query()->findOrFail($batchId);
        $currentCache = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        $this->assertSame('completed', $persistedBatch->status);
        $this->assertNull($persistedBatch->error_message);
        $this->assertSame('completed', $currentCache['status']);
        $this->assertSame($completedCache['result'], $currentCache['result']);
        $this->assertNotNull($persistedBatch->completion_notified_at);
        $this->assertNull($persistedBatch->failure_notified_at);
        $this->assertSame($completionNotificationCount, $this->batchNotifications($batchId, 'import_pegawai')->count());
        $this->assertCount(0, $this->batchNotifications($batchId, 'import_pegawai_gagal'));
    }

    /** Kegagalan worker sebelum claim processing tetap harus membuat batch queued dapat diinspeksi sebagai gagal. */
    public function test_failure_before_processing_claim_marks_queued_batch_failed(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $jobToken = (string) Str::uuid();
        $this->persistQueuedBatch($batchId, $user, $jobToken);

        $cachedBatch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);
        $cachedBatch['status'] = 'queued';
        Cache::put(UploadImportBatchAction::CACHE_PREFIX.$batchId, $cachedBatch, now()->addMinutes(10));

        $job = new ImportEmployeeBatchJob($batchId, $user->id, null, null, $jobToken);
        $job->failed(new \RuntimeException('Worker gagal sebelum claim'));

        $this->assertSame('failed', ImportBatch::query()->findOrFail($batchId)->status);
        $this->assertSame('failed', Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['status']);
    }

    /** Callback terminal menyimpan counter parsial dan tidak membocorkan exception ke notifikasi pengguna. */
    public function test_terminal_failure_preserves_partial_counters_and_uses_generic_notification(): void
    {
        $user = $this->notifiableAdmin();
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->twoRowCsv()),
            'utama',
            $user,
        );
        $batchId = $batch['batch_id'];
        app(ValidateImportBatchAction::class)->execute($batchId, null, $user);
        $jobToken = (string) Str::uuid();
        $this->persistQueuedBatch($batchId, $user, $jobToken);
        ImportBatch::query()->whereKey($batchId)->update([
            'status' => 'processing',
            'processed_valid_count' => 1,
            'inserted_count' => 1,
            'skipped_count' => 2,
            'failed_count' => 3,
            'processing_token' => $jobToken,
            'lease_expires_at' => now()->addMinute(),
        ]);

        $logSpy = Log::spy();
        (new ImportEmployeeBatchJob($batchId, $user->id, null, null, $jobToken))
            ->failed(new \RuntimeException('detail internal sangat rahasia'));

        $terminal = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('failed', $terminal->status);
        $this->assertSame(1, $terminal->processed_valid_count);
        $this->assertSame(1, $terminal->inserted_count);
        $this->assertSame(2, $terminal->skipped_count);
        $this->assertSame(3, $terminal->failed_count);
        $this->assertSame(
            'Proses import pegawai gagal. Silakan coba kembali atau hubungi administrator.',
            $terminal->error_message,
        );
        $this->assertNotNull($terminal->failure_notified_at);

        $notification = $this->batchNotifications($batchId, 'import_pegawai_gagal')->sole();
        $this->assertStringNotContainsString('detail internal sangat rahasia', $notification->body);
        $this->assertStringNotContainsString(
            'detail internal sangat rahasia',
            Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId)['error_message'],
        );

        $report = app(DownloadImportReportAction::class)->execute($terminal);
        ob_start();
        ($report->getCallback())();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString('Proses import pegawai gagal.', $csv);
        $this->assertStringNotContainsString('detail internal sangat rahasia', $csv);

        $logSpy->shouldHaveReceived(
            'error',
            fn (string $message, array $context): bool => $message === 'Job import pegawai gagal setelah retry maksimum.'
                && ($context['batch_id'] ?? null) === $batchId
                && ($context['exception_class'] ?? null) === \RuntimeException::class
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'detail internal sangat rahasia'),
        );
    }

    /** Callback attempt lama tidak boleh mengubah attempt baru yang sudah memiliki token berbeda. */
    public function test_stale_failure_callback_cannot_fail_new_processing_owner(): void
    {
        $user = User::factory()->adminKepegawaian()->create();
        $batchId = $this->validatedBatchId($user);
        $oldToken = (string) Str::uuid();
        $newToken = (string) Str::uuid();
        $this->persistQueuedBatch($batchId, $user, $newToken);
        ImportBatch::query()->whereKey($batchId)->update([
            'status' => 'processing',
            'processing_token' => $newToken,
            'lease_expires_at' => now()->addMinute(),
        ]);

        (new ImportEmployeeBatchJob($batchId, $user->id, null, null, $oldToken))
            ->failed(new \RuntimeException('attempt lama gagal'));

        $current = ImportBatch::query()->findOrFail($batchId);
        $this->assertSame('processing', $current->status);
        $this->assertSame($newToken, $current->processing_token);
        $this->assertNull($current->error_message);
        $this->assertNull($current->failure_notified_at);
    }

    private function validatedBatchId(User $user): string
    {
        $batch = app(UploadImportBatchAction::class)->execute(
            $this->csvFile($this->validCsv()),
            'utama',
            $user,
        );
        app(ValidateImportBatchAction::class)->execute($batch['batch_id'], null, $user);

        return $batch['batch_id'];
    }

    private function notifiableAdmin(): User
    {
        $employee = Employee::factory()->create();

        return User::factory()->adminKepegawaian()->create(['employee_id' => $employee->id]);
    }

    /** @return Collection<int, SimpegNotification> */
    private function batchNotifications(string $batchId, string $type)
    {
        return SimpegNotification::query()
            ->where('type', $type)
            ->get()
            ->filter(fn (SimpegNotification $notification): bool => ($notification->data['batch_id'] ?? null) === $batchId)
            ->values();
    }

    private function persistQueuedBatch(string $batchId, User $user, ?string $processingToken = null): void
    {
        $batch = Cache::get(UploadImportBatchAction::CACHE_PREFIX.$batchId);

        ImportBatch::create([
            'id' => $batchId,
            'user_id' => $user->id,
            'filename' => $batch['filename'],
            'type' => $batch['type'],
            'status' => 'queued',
            'total_rows' => $batch['total_rows'],
            'valid_count' => $batch['validation']['valid_count'],
            'inserted_count' => 0,
            'skipped_count' => $batch['validation']['skip_count'],
            'failed_count' => $batch['validation']['error_count'],
            'processed_valid_count' => 0,
            'processing_token' => $processingToken,
            'row_issues' => [],
            'execution_payload' => [
                'filename' => $batch['filename'],
                'type' => $batch['type'],
                'validation' => $batch['validation'],
            ],
        ]);
    }

    private function legacySerializedJobPayload(string $batchId, string $userId): string
    {
        $processingToken = 'legacy-payload-processing-token';
        $payload = serialize(new ImportEmployeeBatchJob(
            $batchId,
            $userId,
            processingToken: $processingToken,
        ));
        $propertyFragment = serialize("\0*\0processingToken").serialize($processingToken);
        $legacyPayload = str_replace($propertyFragment, '', $payload, $propertyReplacementCount);
        $legacyPayload = preg_replace_callback(
            '/^(O:\d+:"[^"]+":)(\d+)(:\{)/',
            /** @param array<int, string> $matches */
            static fn (array $matches): string => $matches[1].((int) $matches[2] - 1).$matches[3],
            $legacyPayload,
            1,
            $headerReplacementCount,
        );

        if ($propertyReplacementCount !== 1 || $headerReplacementCount !== 1 || $legacyPayload === null) {
            throw new \LogicException('Fixture payload job legacy gagal dibentuk.');
        }

        return $legacyPayload;
    }

    private function observeImportJobPublications(): ImportJobPublishObserverQueue
    {
        $connection = 'import-publish-observer-'.Str::uuid();
        $observer = new ImportJobPublishObserverQueue;

        Queue::extend(
            $connection,
            static fn (): ConnectorInterface => new ImportJobPublishObserverConnector($observer),
        );
        config([
            'queue.default' => $connection,
            "queue.connections.{$connection}" => ['driver' => $connection],
        ]);

        return $observer;
    }

    private function postJsonWithCsrf(string $uri, array $data)
    {
        return $this->withSession(['_token' => 'test-token'])
            ->postJson($uri, $data, ['X-CSRF-TOKEN' => 'test-token']);
    }

    private function validCsv(string $namaDenganGelar = 'Budi Santoso'): string
    {
        $headers = [
            'Nama Pegawai', 'Email Pegawai', 'Golongan', 'Jabatan', 'Kelas Jabatan', 'NIP',
            'Nomor Telepon', 'Pangkat', 'Pendidikan Terakhir', 'Pensiun', 'Person', 'Person Formula',
            'Prodi Pendidikan Terakhir', 'Status Kepegawaian', 'Tanggal Lahir',
        ];
        $row = [
            $namaDenganGelar, 'budi@example.com', 'III/a', 'Analis Kepegawaian', '7',
            '198001012006041001', '081234567890', 'Penata Muda', 'S1', '2038-01-01',
            'Budi Santoso', 'Budi Santoso', 'Manajemen', 'PNS', '1980-01-01',
        ];

        return implode(',', $headers)."\n".implode(',', $row)."\n";
    }

    private function twoRowCsv(): string
    {
        $firstRow = [
            'Budi Santoso', 'budi@example.com', 'III/a', 'Analis Kepegawaian', '7',
            '198001012006041001', '081234567890', 'Penata Muda', 'S1', '2038-01-01',
            'Budi Santoso', 'Budi Santoso', 'Manajemen', 'PNS', '1980-01-01',
        ];
        $secondRow = [
            'Siti Aminah', 'siti@example.com', 'III/b', 'Analis Kepegawaian', '8',
            '198101012007041002', '081234567891', 'Penata Muda Tk. I', 'S1', '2039-01-01',
            'Siti Aminah', 'Siti Aminah', 'Administrasi Negara', 'PNS', '1981-01-01',
        ];

        return implode(',', [
            'Nama Pegawai', 'Email Pegawai', 'Golongan', 'Jabatan', 'Kelas Jabatan', 'NIP',
            'Nomor Telepon', 'Pangkat', 'Pendidikan Terakhir', 'Pensiun', 'Person', 'Person Formula',
            'Prodi Pendidikan Terakhir', 'Status Kepegawaian', 'Tanggal Lahir',
        ])."\n".implode(',', $firstRow)."\n".implode(',', $secondRow)."\n";
    }

    private function csvFile(string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'employees');
        file_put_contents($path, $content);

        return new UploadedFile($path, 'employees.csv', 'text/csv', null, true);
    }
}

/** Queue test-only yang mempertahankan pipeline enqueue after-commit Laravel. */
final class ImportJobPublishObserverQueue extends BaseQueue implements QueueContract
{
    /** @var list<string> */
    private array $publishedPayloads = [];

    public function publishedCount(): int
    {
        return count($this->publishedPayloads);
    }

    public function size($queue = null): int
    {
        return $this->publishedCount();
    }

    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?? 'default', $data),
            $queue,
            null,
            function (string $payload): int {
                $this->publishedPayloads[] = $payload;

                return $this->publishedCount();
            },
        );
    }

    public function pushRaw($payload, $queue = null, array $options = []): int
    {
        $this->publishedPayloads[] = $payload;

        return $this->publishedCount();
    }

    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $queue ?? 'default', $data, $delay),
            $queue,
            $delay,
            function (string $payload): int {
                $this->publishedPayloads[] = $payload;

                return $this->publishedCount();
            },
        );
    }

    public function pop($queue = null): null
    {
        return null;
    }
}

/** Connector test-only yang mengekspos observer tanpa mengganti perilaku transaksi queue. */
final class ImportJobPublishObserverConnector implements ConnectorInterface
{
    public function __construct(private readonly ImportJobPublishObserverQueue $queue) {}

    public function connect(array $config): QueueContract
    {
        return $this->queue;
    }
}
